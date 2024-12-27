<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Database;

use Nette;


/**
 * Processes SQL queries with parameter substitution.
 * Supports named parameters, array expansions and other SQL preprocessing features.
 */
class SqlPreprocessor
{
	private const
		ModeAnd = 'and',       // (key [operator] value) AND ...
		ModeOr = 'or',         // (key [operator] value) OR ...
		ModeSet = 'set',       // key=value, key=value, ...
		ModeValues = 'values', // (key, key, ...) VALUES (value, value, ...)
		ModeOrder = 'order',   // key, key DESC, ...
		ModeList = 'list';     // value, value, ...  |  (tuple), (tuple), ...

	private const CommandToMode = [
		'INSERT' => self::ModeValues,
		'REPLACE' => self::ModeValues,
		'KEY UPDATE' => self::ModeSet,
		'SET' => self::ModeSet,
		'WHERE' => self::ModeAnd,
		'HAVING' => self::ModeAnd,
		'ORDER BY' => self::ModeOrder,
		'GROUP BY' => self::ModeOrder,
	];

	private const ParametricCommands = [
		'SELECT' => 1,
		'INSERT' => 1,
		'UPDATE' => 1,
		'DELETE' => 1,
		'REPLACE' => 1,
		'EXPLAIN' => 1,
	];

	private readonly Connection $connection;
	private readonly Driver $driver;
	private array $params;
	private array $remaining;
	private int $counter;
	private bool $useParams;

	/** values|set|and|order|items */
	private ?string $arrayMode;


	public function __construct(Connection $connection)
	{
		$this->connection = $connection;
		$this->driver = $connection->getDriver();
	}


	/**
	 * Processes SQL query with parameter substitution.
	 * @return array{string, array}
	 */
	public function process(array $params, bool $useParams = false): array
	{
		$this->params = $params;
		$this->counter = 0;
		$prev = -1;
		$this->remaining = [];
		$this->arrayMode = null;
		$this->useParams = $useParams;
		$res = [];

		while ($this->counter < count($params)) {
			$param = $params[$this->counter++];

			if (($this->counter === 2 && count($params) === 2) || !is_scalar($param)) {
				$res[] = $this->formatParameter($param);

			} elseif (is_string($param) && $this->counter > $prev + 1) {
				$prev = $this->counter;
				$this->arrayMode = null;
				$res[] = Nette\Utils\Strings::replace(
					$param,
					<<<'X'
						~
							'[^']*+'
							|"[^"]*+"
							|\?[a-z]*
							|^\s*+(?:\(?\s*SELECT|INSERT|UPDATE|DELETE|REPLACE|EXPLAIN)\b
							|\b(?:SET|WHERE|HAVING|ORDER\ BY|GROUP\ BY|KEY\ UPDATE)(?=\s*$|\s*\?)
							|\bIN\s+(?:\?|\(\?\))
							|/\*.*?\*/
							|--[^\n]*
						~Dsix
						X,
					$this->parsePart(...),
				);
			} else {
				throw new Nette\InvalidArgumentException('There are more parameters than placeholders.');
			}
		}

		return [implode(' ', $res), $this->remaining];
	}


	/**
	 * Handles string literals, comments, and SQL placeholders.
	 */
	private function parsePart(array $match): string
	{
		$match = $match[0];
		if (in_array($match[0], ["'", '"', '/', '-'], true)) { // string or comment
			return $match;

		} elseif (!str_contains($match, '?')) { // command
			$command = ltrim(strtoupper($match), "\t\n\r (");
			$this->arrayMode = self::CommandToMode[$command] ?? null;
			$this->useParams = isset(self::ParametricCommands[$command]) || $this->useParams;
			return $match;
		}

		if ($this->counter >= count($this->params)) {
			throw new Nette\InvalidArgumentException('There are more placeholders than passed parameters.');
		}
		$param = $this->params[$this->counter++];
		if ($match[0] === '?') { // ?[mode]
			return match ($mode = substr($match, 1)) {
				'' => $this->formatParameter($param),
				'name' => $this->formatNameParameter($param),
				self::ModeAnd, self::ModeOr, self::ModeSet, self::ModeValues, self::ModeOrder, self::ModeList => $this->formatArrayParameter($param, $mode),
				default => throw new Nette\InvalidArgumentException("Unknown placeholder $match."),
			};
		} else { // IN (?)
			return 'IN (' . (is_array($param) ? $this->formatList($param) : $this->formatValue($param)) . ')';
		}
	}


	/**
	 * Formats a value for use in SQL query where ? placeholder is used.
	 * For arrays, the formatting is determined by last SQL keyword before the placeholder
	 */
	private function formatParameter(mixed $value): string
	{
		$fallback = fn() => is_object($value) && !$value instanceof \Traversable
			? throw new Nette\InvalidArgumentException('Unexpected type of parameter: ' . get_debug_type($value))
			: $this->formatArrayParameter($value, $this->arrayMode ?? self::ModeSet);
		return $this->formatValue($value, $fallback);
	}


	/**
	 * Formats a value for ?name placeholder in SQL query.
	 */
	private function formatNameParameter(mixed $value): string
	{
		if (!is_string($value)) {
			$type = get_debug_type($value);
			throw new Nette\InvalidArgumentException("Placeholder ?name expects string, $type given.");
		}

		return $this->delimit($value);
	}


	/**
	 * Formats array value for ?mode placeholder in SQL query.
	 */
	private function formatArrayParameter(mixed $value, string $mode): string
	{
		if ($value instanceof \Traversable && !$value instanceof Table\ActiveRow) {
			$value = iterator_to_array($value);
		} elseif (!is_array($value)) {
			$type = get_debug_type($value);
			throw new Nette\InvalidArgumentException("Placeholder ?$mode expects array or Traversable object, $type given.");
		}

		return match ($mode) {
			self::ModeValues => array_key_exists(0, $value) ? $this->formatMultiInsert($value) : $this->formatInsert($value),
			self::ModeSet => $this->formatAssigns($value),
			self::ModeList => $this->formatList($value),
			self::ModeAnd, self::ModeOr => $this->formatWhere($value, $mode),
			self::ModeOrder => $this->formatOrderBy($value),
		};
	}


	/**
	 * Formats a single value for use in SQL query.
	 */
	private function formatValue(mixed $value, ?\Closure $fallback = null): string
	{
		if ($this->useParams && (is_scalar($value) || is_resource($value))) {
			$this->remaining[] = $value;
			return '?';

		} elseif ($value instanceof SqlLiteral) {
			[$res, $params] = (clone $this)->process([$value->getSql(), ...$value->getParameters()], $this->useParams);
			$this->remaining = array_merge($this->remaining, $params);
			return $res;
		}

		return match (true) {
			is_int($value) => (string) $value,
			is_bool($value) => (string) (int) $value,
			is_float($value) => rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.'),
			is_resource($value) => $this->connection->quote(stream_get_contents($value)),
			is_string($value) => $this->connection->quote($value),
			$value === null => 'NULL',
			$value instanceof Table\ActiveRow => $this->formatValue($value->getPrimary()),
			$value instanceof \DateTimeInterface => $this->driver->formatDateTime($value),
			$value instanceof \DateInterval => $this->driver->formatDateInterval($value),
			$value instanceof \BackedEnum && is_scalar($value->value) => $this->formatValue($value->value),
			$value instanceof \Stringable => $this->formatValue((string) $value),
			default => $fallback
				? $fallback()
				: throw new Nette\InvalidArgumentException('Unexpected type of parameter: ' . get_debug_type($value))
		};
	}


	/**
	 * Output: value, value, ... | (tuple), (tuple), ...
	 */
	private function formatList(array $values): string
	{
		$res = [];
		foreach ($values as $v) {
			$res[] = is_array($v)
				? '(' . $this->formatList($v) . ')'
				: $this->formatValue($v);
		}

		return implode(', ', $res);
	}


	/**
	 * Output format: (key, key, ...) VALUES (value, value, ...)
	 */
	private function formatInsert(array $items): string
	{
		$cols = $vals = [];
		foreach ($items as $k => $v) {
			$cols[] = $this->delimit($k);
			$vals[] = $this->formatValue($v);
		}

		return '(' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
	}


	/**
	 * Output format: (key, key, ...) VALUES (value, value, ...), (value, value, ...), ...
	 */
	private function formatMultiInsert(array $groups): string
	{
		if (!is_array($groups[0]) && !$groups[0] instanceof Row) {
			throw new Nette\InvalidArgumentException('Automaticaly detected multi-insert, but values aren\'t array. If you need try to change ?mode.');
		}

		$cols = array_keys(is_array($groups[0]) ? $groups[0] : iterator_to_array($groups[0]));
		$vals = [];
		foreach ($groups as $group) {
			$rowVals = [];
			foreach ($cols as $k) {
				$rowVals[] = $this->formatValue($group[$k]);
			}

			$vals[] = implode(', ', $rowVals);
		}

		$useSelect = $this->driver->isSupported(Driver::SupportMultiInsertAsSelect);
		return '(' . implode(', ', array_map($this->delimit(...), $cols))
			. ($useSelect ? ') SELECT ' : ') VALUES (')
			. implode($useSelect ? ' UNION ALL SELECT ' : '), (', $vals)
			. ($useSelect ? '' : ')');
	}


	/**
	 * Output format: key=value, key=value, ...
	 */
	private function formatAssigns(array $items): string
	{
		$res = [];
		foreach ($items as $k => $v) {
			if (is_int($k)) { // value, value, ...
				$res[] = $this->formatValue($v);
			} elseif (str_ends_with($k, '=')) { // key+=value, key-=value, ...
				$col = $this->delimit(substr($k, 0, -2));
				$res[] = $col . '=' . $col . ' ' . substr($k, -2, 1) . ' ' . $this->formatValue($v);
			} else { // key=value, key=value, ...
				$res[] = $this->delimit($k) . '=' . $this->formatValue($v);
			}
		}

		return implode(', ', $res);
	}


	/**
	 * Output format: (key [operator] value) AND/OR ...
	 */
	private function formatWhere(array $items, string $mode): string
	{
		$res = [];
		foreach ($items as $k => $v) {
			if (is_int($k)) {
				$res[] = $this->formatValue($v);
				continue;
			}

			[$k, $operator] = explode(' ', $k . ' ');
			$k = $this->delimit($k);
			if (is_array($v)) {
				if ($v) {
					$res[] = $k . ' ' . ($operator ? $operator . ' ' : '') . 'IN (' . $this->formatList(array_values($v)) . ')';
				} elseif ($operator === 'NOT') {
				} else {
					$res[] = '1=0';
				}
			} else {
				$v = $this->formatValue($v);
				$operator = $v === 'NULL'
					? ($operator === 'NOT' ? 'IS NOT' : ($operator ?: 'IS'))
					: ($operator ?: '=');
				$res[] = $k . ' ' . $operator . ' ' . $v;
			}
		}

		return $items
			? '(' . implode(') ' . strtoupper($mode) . ' (', $res) . ')'
			: '1=1';
	}


	/**
	 * Output format: key, key DESC, ...
	 */
	private function formatOrderBy(array $items): string
	{
		$res = [];
		foreach ($items as $k => $v) {
			$res[] = $this->delimit($k) . ($v > 0 ? '' : ' DESC');
		}

		return implode(', ', $res);
	}


	/**
	 * Escapes and delimits identifier for use in SQL query.
	 */
	private function delimit(string $name): string
	{
		return implode('.', array_map($this->driver->delimite(...), explode('.', $name)));
	}
}

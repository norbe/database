<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Database;

use JetBrains\PhpStorm\Language;
use Nette;
use Nette\Database\Conventions\StaticConventions;


/**
 * Provides high-level database layer with ActiveRow pattern.
 */
class Explorer
{
	private readonly Conventions $conventions;


	public function __construct(
		private readonly Connection $connection,
		private readonly IStructure $structure,
		?Conventions $conventions = null,
		private readonly ?Nette\Caching\Cache $cache = null,
	) {
		$this->conventions = $conventions ?: new StaticConventions;
	}


	public function beginTransaction(): void
	{
		$this->connection->beginTransaction();
	}


	public function commit(): void
	{
		$this->connection->commit();
	}


	public function rollBack(): void
	{
		$this->connection->rollBack();
	}


	public function transaction(callable $callback): mixed
	{
		return $this->connection->transaction(fn() => $callback($this));
	}


	public function getInsertId(?string $sequence = null): int|string
	{
		return $this->connection->getInsertId($sequence);
	}


	/**
	 * Generates and executes SQL query.
	 * @param  literal-string  $sql
	 */
	public function query(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): Result
	{
		return $this->connection->query($sql, ...$params);
	}


	/** @deprecated  use query() */
	public function queryArgs(string $sql, array $params): Result
	{
		trigger_error(__METHOD__ . '() is deprecated, use query()', E_USER_DEPRECATED);
		return $this->connection->query($sql, ...$params);
	}


	/**
	 * Returns table selection.
	 */
	public function table(string $table): Table\Selection
	{
		return new Table\Selection($this, $table);
	}


	public function getConnection(): Connection
	{
		return $this->connection;
	}


	public function getDatabaseEngine(): Drivers\Engine
	{
		return $this->connection->getDatabaseEngine();
	}


	public function getStructure(): IStructure
	{
		return $this->structure;
	}


	public function getConventions(): Conventions
	{
		return $this->conventions;
	}


	public function getCache(): ?Nette\Caching\Cache
	{
		return $this->cache;
	}


	/********************* shortcuts ****************d*g**/


	/**
	 * Shortcut for query()->fetch()
	 * @param  literal-string  $sql
	 */
	public function fetch(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): ?Row
	{
		return $this->connection->query($sql, ...$params)->fetch();
	}


	/**
	 * Shortcut for query()->fetchAssoc()
	 * @param  literal-string  $sql
	 */
	public function fetchAssoc(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): ?array
	{
		return $this->connection->query($sql, ...$params)->fetchAssoc();
	}


	/**
	 * Shortcut for query()->fetchField()
	 * @param  literal-string  $sql
	 */
	public function fetchField(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): mixed
	{
		return $this->connection->query($sql, ...$params)->fetchField();
	}


	/**
	 * Shortcut for query()->fetchList()
	 * @param  literal-string  $sql
	 */
	public function fetchList(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): ?array
	{
		return $this->connection->query($sql, ...$params)->fetchList();
	}


	/**
	 * Shortcut for query()->fetchList()
	 * @param  literal-string  $sql
	 */
	public function fetchFields(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): ?array
	{
		return $this->connection->query($sql, ...$params)->fetchList();
	}


	/**
	 * Shortcut for query()->fetchPairs()
	 * @param  literal-string  $sql
	 */
	public function fetchPairs(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): array
	{
		return $this->connection->query($sql, ...$params)->fetchPairs();
	}


	/**
	 * Shortcut for query()->fetchAll()
	 * @param  literal-string  $sql
	 */
	public function fetchAll(#[Language('SQL')] string $sql, #[Language('GenericSQL')] ...$params): array
	{
		return $this->connection->query($sql, ...$params)->fetchAll();
	}


	/**
	 * Creates SQL literal value.
	 */
	public static function literal(string $value, ...$params): SqlLiteral
	{
		return new SqlLiteral($value, $params);
	}
}


class_exists(Context::class);

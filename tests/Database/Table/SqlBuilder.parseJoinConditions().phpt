<?php

/**
 * Test: Nette\Database\Table\SqlBuilder: parseJoinConditions().
 * @dataProvider? ../databases.ini
 */

use Tester\Assert;
use Nette\Database\ISupplementalDriver;
use Nette\Database\Table\SqlBuilder;

require __DIR__ . '/../connect.inc.php'; // create $connection

Nette\Database\Helpers::loadFromFile($connection, __DIR__ . "/../files/{$driverName}-nette_test1.sql");

class SqlBuilderMock extends SqlBuilder
{
	public function parseJoinConditions(& $joins, $joinConditions)
	{
		return parent::parseJoinConditions($joins, $joinConditions);
	}
	public function buildJoinConditions()
	{
		return parent::buildJoinConditions();
	}
	public function parseJoins(& $joins, & $query)
	{
		parent::parseJoins($joins, $query);
	}
	public function buildQueryJoins(array $joins, array $leftJoinConditions = [])
	{
		return parent::buildQueryJoins($joins, $leftJoinConditions);
	}
}

$driver = $connection->getSupplementalDriver();

test(function() use ($context) { // test circular reference
	$sqlBuilder = new SqlBuilderMock('author', $context);
	$sqlBuilder->addJoinCondition(':book(translator)', ':book(translator).translator_id = :book(translator).next_volume.translator_id');
	Assert::exception(function() use ($sqlBuilder){
		$sqlBuilder->buildSelectQuery();
	}, '\Nette\InvalidArgumentException');

	$sqlBuilder = new SqlBuilderMock('author', $context);
	$sqlBuilder->addJoinCondition(':book.next_volume', ':book.next_volume.translator_id = :book.translator.id');
	$sqlBuilder->addJoinCondition(':book.translator', ':book.translator.id = :book.next_volume.translator_id');
	Assert::exception(function() use ($sqlBuilder){
		$sqlBuilder->buildSelectQuery();
	}, '\Nette\InvalidArgumentException');

	$sqlBuilder = new SqlBuilderMock('author', $context);
	$sqlBuilder->addJoinCondition(':book.next_volume', ':book.next_volume.translator_id = :book.translator.id');
	$sqlBuilder->addJoinCondition(':book.translator', ':book.translator.id = :book.author.id');
	$sqlBuilder->addJoinCondition(':book.author', ':book.author.id = :book.next_volume.author_id');
	Assert::exception(function() use ($sqlBuilder){
		$sqlBuilder->buildSelectQuery();
	}, '\Nette\InvalidArgumentException');
});

test(function() use ($context, $driver) {
	$sqlBuilder = new SqlBuilderMock('author', $context);
	$sqlBuilder->addJoinCondition(':book(translator)', ':book(translator).id > 2');
	$sqlBuilder->addJoinCondition(':book:book_tag_alt', ':book:book_tag_alt.state ?', 'private');
	$joins = array();
	$leftJoinConditions = $sqlBuilder->parseJoinConditions($joins, $sqlBuilder->buildJoinConditions());
	$join = $sqlBuilder->buildQueryJoins($joins, $leftJoinConditions);

	if ($driver->isSupported(ISupplementalDriver::SUPPORT_SCHEMA)) {
		Assert::same(
			'LEFT JOIN public.book book ON author.id = book.author_id AND (book.id > 2) ' .
			'LEFT JOIN public.book_tag_alt book_tag_alt ON book.id = book_tag_alt.book_id AND (book_tag_alt.state = ?)',
			trim($join)
		);
	} else {
		Assert::same(
			'LEFT JOIN book ON author.id = book.author_id AND (book.id > 2) ' .
			'LEFT JOIN book_tag_alt ON book.id = book_tag_alt.book_id AND (book_tag_alt.state = ?)',
			trim($join)
		);
	}
});
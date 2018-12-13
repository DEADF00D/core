<?php
/**
 * @author Thomas Müller <thomas-mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace Test\Lock\Persistent;

use OC\Lock\Persistent\Lock;
use OC\Lock\Persistent\LockMapper;
use OC\User\Account;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Lock\Persistent\ILock;
use Test\TestCase;
use Doctrine\DBAL\Platforms\SqlitePlatform;

/**
 * Class LockMapperTest
 *
 * @package Test\Lock\Persistent
 * @group DB
 */
class LockMapperTest extends TestCase {
	/** @var IDBConnection */
	private $db;
	/** @var Account */
	private $account;
	/** @var int */
	private $fileCacheId;
	/** @var int */
	private $fileCacheParentId;
	/** @var int */
	private $storageId;
	/** @var int */
	private $unrelatedStorageId;
	/** @var LockMapper */
	private $mapper;
	/** @var Lock[] */
	private $locks = [];
	/** @var string */
	private $parentPath;
	/** @var string */
	private $path;
	/** @var ITimeFactory */
	private $timeFactory;

	private function insertFileCacheEntry($storage, $path) {
		$insertFileCache = $this->db->getQueryBuilder();
		$insertFileCache->insert('filecache')
			->values([
				'storage' => $insertFileCache->createNamedParameter($storage),
				'name' => $insertFileCache->createNamedParameter(basename($path)),
				'path' => $insertFileCache->createNamedParameter($path),
				'path_hash' => $insertFileCache->createNamedParameter(\md5($path))
			])
			->execute();
		return $insertFileCache->getLastInsertId();
	}

	public function setUp() {
		parent::setUp();

		$this->db = \OC::$server->getDatabaseConnection();

		$this->storageId = 666;
		$this->unrelatedStorageId = 667;
		$this->parentPath = 'foo_foo';
		$this->path = 'foo_foo/bar';

		// insert test entities in file cache
		$this->fileCacheParentId = $this->insertFileCacheEntry($this->storageId, $this->parentPath);
		$this->fileCacheId = $this->insertFileCacheEntry($this->storageId, $this->path);
		// unrelated entries
		$this->insertFileCacheEntry($this->unrelatedStorageId, $this->parentPath);
		$this->insertFileCacheEntry($this->unrelatedStorageId, $this->path);

		// insert test entity in account table
		$this->account = new Account();
		$this->account->setUserId(\uniqid('testUser', true));
		$this->account->setBackend('TestBackend');
		$this->account->setHome('/');

		\OC::$server->getAccountMapper()
			->insert($this->account);

		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->timeFactory->method('getTime')->willReturn(123456);

		// mapper to use
		$this->mapper = new LockMapper($this->db, $this->timeFactory);
	}

	public function providesInvalidEntities() {
		$lock = new Lock();
		$lock->setToken('12345');
		$lock->setTokenHash('12345');
		return [
			['token_hash does not match the token of the lock', $lock, 'insert'],
			['token_hash does not match the token of the lock', $lock, 'update'],
			['Wrong entity type used', new Account(), 'insert'],
			['Wrong entity type used', new Account(), 'update']
		];
	}

	protected function tearDown() {
		foreach ($this->locks as $lock) {
			$this->mapper->delete($lock);
		}

		$q = $this->db->getQueryBuilder();
		$q->delete('filecache')
			->where($q->expr()->eq('storage', $q->createNamedParameter($this->storageId)))
			->execute();
		$q->delete('filecache')
			->where($q->expr()->eq('storage', $q->createNamedParameter($this->unrelatedStorageId)))
			->execute();

		\OC::$server->getAccountMapper()
			->delete($this->account);

		parent::tearDown();
	}

	public function testInsert() {
		$lock = new Lock();
		$token = \uniqid('tok', true);
		$lock->setFileId($this->fileCacheId);
		$lock->setToken($token);
		$lock->setCreatedAt(\time());
		$lock->setTimeout(1880);
		$lock->setScope(ILock::LOCK_SCOPE_EXCLUSIVE);
		$lock->setOwnerAccountId($this->account->getId());
		$lock->setDepth(-1);
		$this->mapper->insert($lock);

		$this->locks[]= $lock;

		$l = $this->mapper->getLockByToken($token);
		$this->assertLock($lock, $l);

		$this->mapper->deleteByFileIdAndToken($this->fileCacheId, $token);
		$l = $this->mapper->getLocksByPath($this->storageId, $this->path, false);
		$this->assertCount(0, $l);
	}

	public function testGetLocksByPathDepth0() {
		$lock = new Lock();
		$token = \uniqid('tok', true);
		$lock->setFileId($this->fileCacheId);
		$lock->setToken($token);
		$lock->setCreatedAt(\time());
		$lock->setTimeout(1880);
		$lock->setScope(ILock::LOCK_SCOPE_EXCLUSIVE);
		$lock->setOwnerAccountId($this->account->getId());
		$lock->setDepth(0);
		$this->mapper->insert($lock);

		$this->locks[]= $lock;

		$l = $this->mapper->getLocksByPath($this->storageId, $this->path, false);
		$this->assertCount(1, $l);
		$this->assertLock($lock, $l[0], 'query on child path returns lock with depth 0');

		$l = $this->mapper->getLocksByPath($this->storageId, $this->parentPath, false);
		$this->assertCount(0, $l, 'query on parent path returns no lock with depth 0');

		$l = $this->mapper->getLocksByPath($this->storageId, $this->path, true);
		$this->assertCount(1, $l);
		$this->assertLock($lock, $l[0], 'query+children on child path returns lock with depth 0');

		// parent is able to retrieve for children when asking for children
		$l = $this->mapper->getLocksByPath($this->storageId, $this->parentPath, true);
		$this->assertCount(1, $l);
		$this->assertLock($lock, $l[0], 'query+children on parent returns lock with depth 0 from the child');

		// unrelated storage with same paths
		$l = $this->mapper->getLocksByPath($this->unrelatedStorageId, $this->path, false);
		$this->assertEmpty($l, 'query on unrelated storage yields no result');

		$l = $this->mapper->getLocksByPath($this->unrelatedStorageId, $this->path, true);
		$this->assertEmpty($l, 'query on unrelated storage yields no result');

		$l = $this->mapper->getLocksByPath($this->unrelatedStorageId, $this->parentPath, false);
		$this->assertEmpty($l, 'query on unrelated storage yields no result');

		$l = $this->mapper->getLocksByPath($this->unrelatedStorageId, $this->parentPath, true);
		$this->assertEmpty($l, 'query on unrelated storage yields no result');

		$this->mapper->deleteByFileIdAndToken($this->fileCacheId, $token);
		$l = $this->mapper->getLocksByPath($this->storageId, $this->path, false);
		$this->assertCount(0, $l);
	}

	public function providesNonZeroDepth() {
		return [
			[1],
			[-1], // infinity
		];
	}

	/**
	 * @dataProvider providesNonZeroDepth
	 */
	public function testGetLocksByPathNonZeroDepth($depth) {
		$lock = new Lock();
		$token = \uniqid('tok', true);
		$lock->setFileId($this->fileCacheParentId);
		$lock->setToken($token);
		$lock->setCreatedAt(\time());
		$lock->setTimeout(1880);
		$lock->setScope(ILock::LOCK_SCOPE_EXCLUSIVE);
		$lock->setOwnerAccountId($this->account->getId());
		$lock->setDepth($depth);
		$this->mapper->insert($lock);

		$this->locks[]= $lock;

		$l = $this->mapper->getLocksByPath($this->storageId, $this->path, false);
		$this->assertCount(1, $l);
		$this->assertLock($lock, $l[0], 'query on child path returns lock with depth 1 from parent');

		$l = $this->mapper->getLocksByPath($this->storageId, $this->parentPath, false);
		$this->assertCount(1, $l);
		$this->assertLock($lock, $l[0], 'query on parent path returns lock with depth 1 from parent');

		$l = $this->mapper->getLocksByPath($this->storageId, $this->path, true);
		$this->assertCount(1, $l);
		$this->assertLock($lock, $l[0], 'query+children on child path returns lock with depth 1 from parent');

		$l = $this->mapper->getLocksByPath($this->storageId, $this->parentPath, true);
		$this->assertCount(1, $l);
		$this->assertLock($lock, $l[0], 'query+children on parent path returns lock with depth 1 from parent');

		// unrelated storage with same paths
		$l = $this->mapper->getLocksByPath($this->unrelatedStorageId, $this->path, false);
		$this->assertEmpty($l, 'query on unrelated storage yields no result');

		$l = $this->mapper->getLocksByPath($this->unrelatedStorageId, $this->path, true);
		$this->assertEmpty($l, 'query on unrelated storage yields no result');

		$l = $this->mapper->getLocksByPath($this->unrelatedStorageId, $this->parentPath, false);
		$this->assertEmpty($l, 'query on unrelated storage yields no result');

		$l = $this->mapper->getLocksByPath($this->unrelatedStorageId, $this->parentPath, true);
		$this->assertEmpty($l, 'query on unrelated storage yields no result');

		$this->mapper->deleteByFileIdAndToken($this->fileCacheId, $token);
		$l = $this->mapper->getLocksByPath($this->storageId, $this->path, false);
		$this->assertCount(0, $l);
	}

	/**
	 * @param $expectedMessage
	 * @param $entity
	 * @dataProvider providesInvalidEntities
	 */
	public function testInsertOrUpdateOfInvalid($expectedMessage, $entity, $method) {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($expectedMessage);
		$this->mapper->$method($entity);
	}

	/**
	 * @param Lock $expected
	 * @param Lock $actual
	 */
	private function assertLock(Lock $expected, $actual): void {
		$this->assertEquals($expected->getId(), $actual->getId());
		$this->assertEquals($expected->getFileId(), $actual->getFileId());
		$this->assertEquals($expected->getToken(), $actual->getToken());
		$this->assertEquals($expected->getCreatedAt(), $actual->getCreatedAt());
		$this->assertEquals($expected->getTimeout(), $actual->getTimeout());
	}

	public function testCleanup() {
		// create 2 out dated locks
		$lock0 = $this->createLockAnInsert();
		$lock1 = $this->createLockAnInsert();

		// and one lock which is current
		$lock2 = $this->createLockAnInsert(\time());

		$this->lockExists($lock0->getToken());
		$this->lockExists($lock1->getToken());
		$this->lockExists($lock2->getToken());

		$this->mapper->cleanup();

		$this->lockExists($lock0->getToken(), false);
		$this->lockExists($lock1->getToken(), false);
		$this->lockExists($lock2->getToken());
	}

	private function lockExists($token0, $exists = true) {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->createFunction('count(*) as `count`'))
			->from($this->mapper->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token0)))
			->execute()
			->fetch();

		$this->assertEquals($exists ? 1 : 0, (int) $result['count']);
	}

	/**
	 * @return Lock
	 */
	private function createLockAnInsert(int $createdAt = 0): Lock {
		$token = \uniqid('tok', true);

		$lock = new Lock();
		$lock->setFileId($this->fileCacheId);
		$lock->setCreatedAt($createdAt);
		$lock->setTimeout(1880);
		$lock->setScope(ILock::LOCK_SCOPE_EXCLUSIVE);
		$lock->setDepth(0);
		$lock->setToken($token);

		$this->mapper->insert($lock);
		$this->locks[]= $lock;

		return $lock;
	}

	/**
	 * @expectedException \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function testDeleteUserDeletesLock() {
		if ($this->db->getDatabasePlatform() instanceof SqlitePlatform) {
			// remove when https://github.com/doctrine/dbal/issues/1204 and https://github.com/doctrine/dbal/issues/2833 are fixed
			$this->markTestSkipped("No cascade delete possible on Sqlite with Doctrine DBAL");
		}

		$lock = new Lock();
		$token = \uniqid('tok', true);
		$lock->setFileId($this->fileCacheId);
		$lock->setToken($token);
		$lock->setCreatedAt(\time());
		$lock->setTimeout(1880);
		$lock->setOwnerAccountId($this->account->getId());
		$lock->setScope(ILock::LOCK_SCOPE_EXCLUSIVE);
		$lock->setDepth(0);
		$this->mapper->insert($lock);

		\OC::$server->getAccountMapper()
			->delete($this->account);

		$this->mapper->getLockByToken($token);
	}
}

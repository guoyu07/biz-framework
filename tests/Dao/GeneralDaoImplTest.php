<?php

namespace Tests;

use Codeages\Biz\Framework\Context\Biz;
use Codeages\Biz\Framework\Provider\DoctrineServiceProvider;
use PHPUnit\Framework\TestCase;

class GeneralDaoImplTest extends TestCase
{
    const NOT_EXIST_ID = 9999;

    public function __construct()
    {
        $config = array(
            'db.options' => array(
                'driver' => getenv('DB_DRIVER'),
                'dbname' => getenv('DB_NAME'),
                'host' => getenv('DB_HOST'),
                'user' => getenv('DB_USER'),
                'password' => getenv('DB_PASSWORD'),
                'charset' => getenv('DB_CHARSET'),
                'port' => getenv('DB_PORT'),
            ),
        );
        $biz = new Biz($config);
        $biz['autoload.aliases']['TestProject'] = 'TestProject\Biz';
        $biz->register(new DoctrineServiceProvider());
        $biz->boot();

        $this->biz = $biz;
    }

    public function setUp()
    {
        $this->biz['db']->exec('DROP TABLE IF EXISTS `example`');
        $this->biz['db']->exec("
            CREATE TABLE `example` (
              `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(32) NOT NULL,
              `counter1` int(10) unsigned NOT NULL DEFAULT 0,
              `counter2` int(10) unsigned NOT NULL DEFAULT 0,
              `ids1` varchar(32) NOT NULL DEFAULT '',
              `ids2` varchar(32) NOT NULL DEFAULT '',
              `created_time` int(10) unsigned NOT NULL DEFAULT 0,
              `updated_time` int(10) unsigned NOT NULL DEFAULT 0,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ");
    }

    public function testGet()
    {
        $dao = $this->biz->dao('TestProject:Example:ExampleDao');

        $row = $dao->create(array(
            'name' => 'test1',
        ));

        $found = $dao->get($row['id']);
        $this->assertEquals($row['id'], $found['id']);

        $found = $dao->get(self::NOT_EXIST_ID);
        $this->assertEquals(null, $found);
    }

    public function testCreate()
    {
        $dao = $this->biz->dao('TestProject:Example:ExampleDao');

        $fields = array(
            'name' => 'test1',
            'ids1' => array(1, 2, 3),
            'ids2' => array(1, 2, 3),
        );

        $before = time();

        $saved = $dao->create($fields);

        $this->assertEquals($fields['name'], $saved['name']);
        $this->assertTrue(is_array($saved['ids1']));
        $this->assertCount(3, $saved['ids1']);
        $this->assertTrue(is_array($saved['ids2']));
        $this->assertCount(3, $saved['ids2']);
        $this->assertGreaterThanOrEqual($before, $saved['created_time']);
        $this->assertGreaterThanOrEqual($before, $saved['updated_time']);
    }

    public function testUpdate()
    {
        $dao = $this->biz->dao('TestProject:Example:ExampleDao');

        $row = $dao->create(array(
            'name' => 'test1',
        ));

        $fields = array(
            'name' => 'test2',
            'ids1' => array(1, 2),
            'ids2' => array(1, 2),
        );

        $before = time();
        $saved = $dao->update($row['id'], $fields);

        $this->assertEquals($fields['name'], $saved['name']);
        $this->assertTrue(is_array($saved['ids1']));
        $this->assertCount(2, $saved['ids1']);
        $this->assertTrue(is_array($saved['ids2']));
        $this->assertCount(2, $saved['ids2']);
        $this->assertGreaterThanOrEqual($before, $saved['updated_time']);
    }

    public function testDelete()
    {
        $dao = $this->biz->dao('TestProject:Example:ExampleDao');

        $row = $dao->create(array(
            'name' => 'test1',
        ));

        $deleted = $dao->delete($row['id']);

        $this->assertEquals(1, $deleted);
    }

    public function testWave()
    {
        $dao = $this->biz->dao('TestProject:Example:ExampleDao');

        $row = $dao->create(array(
            'name' => 'test1',
        ));

        $diff = array('counter1' => 1, 'counter2' => 2);
        $waved = $dao->wave(array($row['id']), $diff);
        $row = $dao->get($row['id']);

        $this->assertEquals(1, $waved);
        $this->assertEquals(1, $row['counter1']);
        $this->assertEquals(2, $row['counter2']);

        $diff = array('counter1' => -1, 'counter2' => -1);
        $waved = $dao->wave(array($row['id']), $diff);
        $row = $dao->get($row['id']);

        $this->assertEquals(1, $waved);
        $this->assertEquals(0, $row['counter1']);
        $this->assertEquals(1, $row['counter2']);
    }

    public function testLikeSearch()
    {
        $dao = $this->biz->dao('TestProject:Example:ExampleDao');

        $dao->create(array('name' => 'pre_test1'));
        $dao->create(array('name' => 'pre_test2'));
        $dao->create(array('name' => 'test3_suf'));
        $dao->create(array('name' => 'test4_suf'));
        $dao->create(array('name' => 'test5'));

        $preNames = $dao->search(array('pre_like' => 'pre_'), array('created_time' => 'desc'), 0, 100);

        $sufNames = $dao->search(array('suf_name' => '_suf'), array('created_time' => 'desc'), 0, 100);

        $likeNames = $dao->search(array('like_name' => 'test'), array('created_time' => 'desc'), 0, 100);

        $this->assertCount(2, $preNames);
        $this->assertCount(2, $sufNames);
        $this->assertCount(5, $likeNames);
        $this->assertEquals('pre_test1', $preNames[0]['name']);
        $this->assertEquals('test4_suf', $sufNames[1]['name']);
        $this->assertEquals('test5', $likeNames[4]['name']);
    }

    public function testInSearch()
    {
        $dao = $this->biz->dao('TestProject:Example:ExampleDao');

        $tmp1 = $dao->create(array('name' => 'pre_test1'));
        $dao->create(array('name' => 'pre_test2'));
        $tmp2 = $dao->create(array('name' => 'test3_suf'));
        $dao->create(array('name' => 'test4_suf'));

        $results = $dao->search(array('ids' => array($tmp1['id'], $tmp2['id'])), array('created_time' => 'desc'), 0, 100);

        $this->assertCount(2, $results);

        $results = $dao->search(array('ids' => array()), array('created_time' => 'desc'), 0, 100);

        $this->assertCount(4, $results);
    }

    /**
     * @expectedException \Codeages\Biz\Framework\Dao\DaoException
     */
    public function testInSearchWithException()
    {
        $dao = $this->biz->dao('TestProject:Example:ExampleDao');
        $dao->search(array('ids' => 1), array(), 0, 100);
    }

    public function testCount()
    {
        $dao = $this->biz->dao('TestProject:Example:ExampleDao');

        $dao->create(array('name' => 'test1'));
        $dao->create(array('name' => 'test2'));
        $dao->create(array('name' => 'test3'));

        $count = $dao->count(array('name' => 'test2'));

        $this->assertEquals(1, $count);
    }

    public function testFindInFields()
    {
        $dao = $this->biz->dao('TestProject:Example:ExampleDao');

        $dao->create(array('name' => 'test1', 'ids1' => array('1111'), 'ids2' => array('1111')));
        $dao->create(array('name' => 'test1', 'ids1' => array('1111'), 'ids2' => array('2222')));
        $dao->create(array('name' => 'test2', 'ids1' => array('1111'), 'ids2' => array('3333')));
        $result = $dao->findByNameAndId('test1', '["1111"]');

        $this->assertEquals(count($result), 2);
    }

    public function testTransactional()
    {
        $dao = $this->biz->dao('TestProject:Example:ExampleDao');

        $result = $dao->db()->transactional(function () {
            return 1;
        });

        $this->assertEquals(1, $result);
    }
}

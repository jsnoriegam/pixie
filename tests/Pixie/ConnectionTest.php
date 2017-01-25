<?php namespace Pixie;

use Mockery as m;
class ConnectionTest extends TestCase
{
    private $mysqlConnectionMock;
    private $connection;

    public function setUp()
    {
        parent::setUp();

        $this->mysqlConnectionMock = m::mock('\Pixie\ConnectionAdapters\Mysql');
        $this->mysqlConnectionMock->shouldReceive('connect')->andReturn($this->mockPdo);

        $this->container->setInstance('\Pixie\ConnectionAdapters\Mysqlmock', $this->mysqlConnectionMock);
        $this->connection = new Connection(array('driver' => 'mysqlmock', 'prefix' => 'cb_'), $this->container);
    }

    public function testConnection()
    {
        $this->assertEquals($this->mockPdo, $this->connection->getPdoInstance());
        $this->assertInstanceOf('\PDO', $this->connection->getPdoInstance());
        $this->assertEquals('mysqlmock', $this->connection->getAdapter());
        $this->assertEquals(array('driver' => 'mysqlmock', 'prefix' => 'cb_'), $this->connection->getAdapterConfig());
    }
}
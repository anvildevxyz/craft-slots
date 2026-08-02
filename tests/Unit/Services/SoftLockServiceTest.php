<?php

namespace anvildev\slots\tests\Unit\Services;

use anvildev\slots\services\SoftLockService;
use anvildev\slots\tests\Support\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * SoftLockService Test
 *
 * Tests the soft lock orchestration logic using partial mocks.
 * The service has protected helpers (createRecord, saveRecord, deleteRecord,
 * deleteExpiredRecords, getRecordQuery) specifically designed for mocking.
 *
 * DB-dependent query logic (isLocked, getActiveSoftLocksForDate, countExpiredLocks)
 * requires integration tests with a real database.
 */
class SoftLockServiceTest extends TestCase
{
    /**
     * @beforeClass
     */
    public static function defineCraftStub(): void
    {
        if (!class_exists('Craft', false)) {
            eval('class Craft extends \yii\BaseYii {}');
        }
    }

    /**
     * Create a partial mock that allows mocking the protected helpers
     */
    private function makePartialService(): MockInterface
    {
        $mock = Mockery::mock(SoftLockService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // Provide a mutex mock so createLock() works without full Craft app
        $mutexMock = Mockery::mock(\yii\mutex\Mutex::class);
        $mutexMock->shouldReceive('acquire')->andReturn(true)->byDefault();
        $mutexMock->shouldReceive('release')->andReturn(true)->byDefault();
        $mock->shouldReceive('getMutex')->andReturn($mutexMock)->byDefault();

        return $mock;
    }

    // =========================================================================
    // createLock() - Orchestration logic
    // =========================================================================

    public function testCreateLockReturnsTokenOnSuccess(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('deleteExpiredRecords')->once()->andReturn(0);
        $service->shouldReceive('isLocked')->once()->andReturn(false);

        $mockRecord = $this->createMockRecord();
        $service->shouldReceive('createRecord')->once()->andReturn($mockRecord);
        $service->shouldReceive('saveRecord')->once()->with($mockRecord)->andReturn(true);

        $data = $this->makeSlotData();
        $token = $service->createLock($data, 5);

        $this->assertIsString($token);
        $this->assertEquals(32, strlen($token)); // 16 bytes = 32 hex chars
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $token);
    }

    public function testCreateLockReturnsFalseWhenSlotIsLocked(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('deleteExpiredRecords')->once()->andReturn(0);
        $service->shouldReceive('isLocked')->once()->andReturn(true);
        $service->shouldNotReceive('createRecord');
        $service->shouldNotReceive('saveRecord');

        $result = $service->createLock($this->makeSlotData());

        $this->assertFalse($result);
    }

    public function testCreateLockReturnsFalseWhenSaveFails(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('deleteExpiredRecords')->once()->andReturn(0);
        $service->shouldReceive('isLocked')->once()->andReturn(false);

        $mockRecord = $this->createMockRecord();
        $service->shouldReceive('createRecord')->once()->andReturn($mockRecord);
        $service->shouldReceive('saveRecord')->once()->andReturn(false);

        $result = $service->createLock($this->makeSlotData());

        $this->assertFalse($result);
    }

    public function testCreateLockAlwaysCleansUpExpiredRecords(): void
    {
        $service = $this->makePartialService();
        // Verify garbage collection runs even when slot is locked
        $service->shouldReceive('deleteExpiredRecords')->once()->andReturn(3);
        $service->shouldReceive('isLocked')->once()->andReturn(true);

        $result = $service->createLock($this->makeSlotData());

        // Slot was locked, so createLock returns false — but GC still ran (Mockery verifies once())
        $this->assertFalse($result);
    }

    public function testCreateLockSetsRecordFieldsFromData(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('deleteExpiredRecords')->andReturn(0);
        $service->shouldReceive('isLocked')->andReturn(false);

        $mockRecord = $this->createMockRecord();
        $service->shouldReceive('createRecord')->andReturn($mockRecord);
        $service->shouldReceive('saveRecord')->andReturn(true);

        $data = [
            'serviceId' => 42,
            'employeeId' => 7,
            'locationId' => 3,
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ];

        $service->createLock($data, 10);

        $this->assertEquals(42, $mockRecord->serviceId);
        $this->assertEquals(7, $mockRecord->employeeId);
        $this->assertEquals(3, $mockRecord->locationId);
        $this->assertEquals('2025-06-15', $mockRecord->date);
        $this->assertEquals('14:00', $mockRecord->startTime);
        $this->assertEquals('15:00', $mockRecord->endTime);
        $this->assertNotNull($mockRecord->token);
        $this->assertNotNull($mockRecord->expiresAt);
    }

    public function testCreateLockSetsNullableFieldsToNullWhenAbsent(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('deleteExpiredRecords')->andReturn(0);
        $service->shouldReceive('isLocked')->andReturn(false);

        $mockRecord = $this->createMockRecord();
        $service->shouldReceive('createRecord')->andReturn($mockRecord);
        $service->shouldReceive('saveRecord')->andReturn(true);

        $data = [
            'serviceId' => 1,
            'date' => '2025-06-15',
            'startTime' => '09:00',
            'endTime' => '10:00',
            // No employeeId or locationId
        ];

        $service->createLock($data);

        $this->assertNull($mockRecord->employeeId);
        $this->assertNull($mockRecord->locationId);
    }

    public function testCreateLockPassesCorrectParamsToIsLocked(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('deleteExpiredRecords')->andReturn(0);
        $service->shouldReceive('isLocked')
            ->once()
            ->with('2025-06-15', '14:00', 42, 7, '15:00', 3, null, 1, null)
            ->andReturn(true);

        $data = [
            'serviceId' => 42,
            'employeeId' => 7,
            'locationId' => 3,
            'date' => '2025-06-15',
            'startTime' => '14:00',
            'endTime' => '15:00',
        ];

        $result = $service->createLock($data);

        $this->assertFalse($result); // Locked, so returns false
    }

    public function testCreateLockGeneratesUniqueTokensPerCall(): void
    {
        $tokens = [];
        for ($i = 0; $i < 10; $i++) {
            $service = $this->makePartialService();
            $service->shouldReceive('deleteExpiredRecords')->andReturn(0);
            $service->shouldReceive('isLocked')->andReturn(false);

            $mockRecord = $this->createMockRecord();
            $service->shouldReceive('createRecord')->andReturn($mockRecord);
            $service->shouldReceive('saveRecord')->andReturn(true);

            $tokens[] = $service->createLock($this->makeSlotData());
        }

        // All tokens should be unique
        $this->assertCount(10, array_unique($tokens));
    }

    public function testCreateLockDefaultDurationIsFiveMinutes(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('deleteExpiredRecords')->andReturn(0);
        $service->shouldReceive('isLocked')->andReturn(false);

        $mockRecord = $this->createMockRecord();
        $service->shouldReceive('createRecord')->andReturn($mockRecord);
        $service->shouldReceive('saveRecord')->andReturn(true);

        // The service stores expiresAt as a UTC datetime string (now + 5 min default),
        // so the bounds must be UTC too — a local-tz DateTime would compare wrong.
        $before = new \DateTime('+4 minutes', new \DateTimeZone('UTC'));
        $service->createLock($this->makeSlotData()); // default 5 min
        $after = new \DateTime('+6 minutes', new \DateTimeZone('UTC'));

        $this->assertNotNull($mockRecord->expiresAt);
        // Assert the stored expiry actually lands inside the intended 5-minute window,
        // so a changed/dropped default duration fails this test instead of passing.
        $expiresAt = new \DateTime((string)$mockRecord->expiresAt, new \DateTimeZone('UTC'));
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $expiresAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $expiresAt->getTimestamp());
    }

    // =========================================================================
    // releaseLock() - Token lookup and delete
    // =========================================================================

    public function testReleaseLockReturnsTrueWhenRecordFound(): void
    {
        $mockRecord = $this->createMockRecord();

        $service = $this->makePartialService();
        $service->shouldReceive('getRecordByToken')
            ->with('abc123')
            ->andReturn($mockRecord);
        $service->shouldReceive('deleteRecord')
            ->once()
            ->with($mockRecord)
            ->andReturn(1);

        $result = $service->releaseLock('abc123');

        $this->assertTrue($result);
    }

    public function testReleaseLockReturnsFalseWhenRecordNotFound(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getRecordByToken')
            ->with('nonexistent')
            ->andReturn(null);
        $service->shouldNotReceive('deleteRecord');

        $result = $service->releaseLock('nonexistent');

        $this->assertFalse($result);
    }

    public function testReleaseLockReturnsFalseWhenDeleteFails(): void
    {
        $mockRecord = $this->createMockRecord();

        $service = $this->makePartialService();
        $service->shouldReceive('getRecordByToken')
            ->with('abc123')
            ->andReturn($mockRecord);
        $service->shouldReceive('deleteRecord')
            ->once()
            ->with($mockRecord)
            ->andReturn(0); // 0 rows deleted = failure

        $result = $service->releaseLock('abc123');

        $this->assertFalse($result);
    }

    public function testReleaseLockDeniedOnSessionHashMismatch(): void
    {
        $mockRecord = $this->createMockRecord();
        $mockRecord->sessionHash = hash('sha256', 'session1|127.0.0.1');

        $service = $this->makePartialService();
        $service->shouldReceive('getRecordByToken')
            ->with('abc123')
            ->andReturn($mockRecord);
        $service->shouldNotReceive('deleteRecord');

        $wrongHash = hash('sha256', 'different-session|10.0.0.1');
        $result = $service->releaseLock('abc123', $wrongHash);

        $this->assertFalse($result);
    }

    public function testReleaseLockAllowedOnSessionHashMatch(): void
    {
        $sessionHash = hash('sha256', 'session1|127.0.0.1');
        $mockRecord = $this->createMockRecord();
        $mockRecord->sessionHash = $sessionHash;

        $service = $this->makePartialService();
        $service->shouldReceive('getRecordByToken')
            ->with('abc123')
            ->andReturn($mockRecord);
        $service->shouldReceive('deleteRecord')
            ->once()
            ->with($mockRecord)
            ->andReturn(1);

        $result = $service->releaseLock('abc123', $sessionHash);

        $this->assertTrue($result);
    }

    public function testReleaseLockAllowedWhenRecordHasNoSessionHash(): void
    {
        $mockRecord = $this->createMockRecord();
        $mockRecord->sessionHash = null;

        $service = $this->makePartialService();
        $service->shouldReceive('getRecordByToken')
            ->with('abc123')
            ->andReturn($mockRecord);
        $service->shouldReceive('deleteRecord')
            ->once()
            ->with($mockRecord)
            ->andReturn(1);

        $result = $service->releaseLock('abc123', 'any-hash');

        $this->assertTrue($result);
    }

    // =========================================================================
    // extendLock() - Renewal with a hard lifetime ceiling
    // =========================================================================

    public function testExtendLockReturnsFalseWhenRecordNotFound(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('getRecordByToken')->with('missing')->andReturn(null);
        $service->shouldNotReceive('saveRecord');

        $this->assertFalse($service->extendLock('missing'));
    }

    public function testExtendLockDeniedOnSessionHashMismatch(): void
    {
        $mockRecord = $this->createMockRecord();
        $mockRecord->sessionHash = hash('sha256', 'session1|127.0.0.1');

        $service = $this->makePartialService();
        $service->shouldReceive('getRecordByToken')->with('abc123')->andReturn($mockRecord);
        $service->shouldNotReceive('saveRecord');

        $wrongHash = hash('sha256', 'different-session|10.0.0.1');
        $this->assertFalse($service->extendLock('abc123', 5, $wrongHash));
    }

    public function testExtendLockReturnsFalseWhenAlreadyExpired(): void
    {
        $mockRecord = $this->createMockRecord();
        $mockRecord->sessionHash = null;
        $mockRecord->dateCreated = $this->utc('-4 minutes');
        $mockRecord->expiresAt = $this->utc('-1 minute'); // already lapsed

        $service = $this->makePartialService();
        $service->shouldReceive('getRecordByToken')->with('abc123')->andReturn($mockRecord);
        $service->shouldNotReceive('saveRecord');

        $this->assertFalse($service->extendLock('abc123', 5, null, 30));
    }

    public function testExtendLockRefusesToRenewPastLifetimeCeiling(): void
    {
        // Still-live lock (expiresAt in the future) but far older than the ceiling:
        // this is the indefinite-hold attack — renewal must be denied.
        $mockRecord = $this->createMockRecord();
        $mockRecord->sessionHash = null;
        $mockRecord->dateCreated = $this->utc('-40 minutes');
        $mockRecord->expiresAt = $this->utc('+1 minute');

        $service = $this->makePartialService();
        $service->shouldReceive('getRecordByToken')->with('abc123')->andReturn($mockRecord);
        $service->shouldNotReceive('saveRecord');

        $this->assertFalse($service->extendLock('abc123', 5, null, 30));
    }

    public function testExtendLockSucceedsWithinLifetime(): void
    {
        $mockRecord = $this->createMockRecord();
        $mockRecord->sessionHash = null;
        $mockRecord->dateCreated = $this->utc('-1 minute');
        $mockRecord->expiresAt = $this->utc('+2 minutes');

        $service = $this->makePartialService();
        $service->shouldReceive('getRecordByToken')->with('abc123')->andReturn($mockRecord);
        $service->shouldReceive('saveRecord')->once()->with($mockRecord)->andReturn(true);

        $result = $service->extendLock('abc123', 5, null, 30);

        $this->assertInstanceOf(\DateTime::class, $result);
        $this->assertNotNull($mockRecord->expiresAt);
    }

    public function testExtendLockClampsNewExpiryToLifetimeCeiling(): void
    {
        // Created 28 min ago, ceiling 30 min → at most ~2 min of runway left,
        // so a 5-minute renewal must be clamped down to the ceiling.
        $mockRecord = $this->createMockRecord();
        $mockRecord->sessionHash = null;
        $mockRecord->dateCreated = $this->utc('-28 minutes');
        $mockRecord->expiresAt = $this->utc('+1 minute');

        $service = $this->makePartialService();
        $service->shouldReceive('getRecordByToken')->with('abc123')->andReturn($mockRecord);
        $service->shouldReceive('saveRecord')->once()->with($mockRecord)->andReturn(true);

        $result = $service->extendLock('abc123', 5, null, 30);

        $this->assertInstanceOf(\DateTime::class, $result);
        // New expiry may not exceed dateCreated + 30 min (allow 5s of test slack).
        $ceiling = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('+2 minutes');
        $this->assertLessThanOrEqual(
            $ceiling->getTimestamp() + 5,
            $result->getTimestamp(),
            'Renewal must be clamped to the lifetime ceiling, not extended a full duration'
        );
    }

    // =========================================================================
    // cleanupExpiredLocks() - Delegation
    // =========================================================================

    public function testCleanupExpiredLocksDelegatesToDeleteExpiredRecords(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('deleteExpiredRecords')
            ->once()
            ->andReturn(5);

        $result = $service->cleanupExpiredLocks();

        $this->assertEquals(5, $result);
    }

    public function testCleanupExpiredLocksReturnsZeroWhenNoneExpired(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('deleteExpiredRecords')
            ->once()
            ->andReturn(0);

        $result = $service->cleanupExpiredLocks();

        $this->assertEquals(0, $result);
    }

    // =========================================================================
    // Service instantiation
    // =========================================================================

    public function testServiceIsComponent(): void
    {
        $service = new SoftLockService();
        $this->assertInstanceOf(SoftLockService::class, $service);
    }

    public function testServiceHasExpectedPublicMethods(): void
    {
        $service = new SoftLockService();
        $this->assertTrue(method_exists($service, 'createLock'));
        $this->assertTrue(method_exists($service, 'isLocked'));
        $this->assertTrue(method_exists($service, 'releaseLock'));
        $this->assertTrue(method_exists($service, 'cleanupExpiredLocks'));
        $this->assertTrue(method_exists($service, 'countExpiredLocks'));
        $this->assertTrue(method_exists($service, 'getActiveSoftLocksForDate'));
        $this->assertTrue(method_exists($service, 'getRecordByToken'));
    }

    // =========================================================================
    // createLock() — quantity support
    // =========================================================================

    public function testCreateLockStoresQuantityOnRecord(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('deleteExpiredRecords')->andReturn(0);
        $service->shouldReceive('isLocked')->andReturn(false);

        $mockRecord = $this->createMockRecord();
        $service->shouldReceive('createRecord')->andReturn($mockRecord);
        $service->shouldReceive('saveRecord')->andReturn(true);

        $data = $this->makeSlotData(['quantity' => 3]);
        $service->createLock($data);

        $this->assertEquals(3, $mockRecord->quantity);
    }

    public function testCreateLockDefaultsQuantityToOne(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('deleteExpiredRecords')->andReturn(0);
        $service->shouldReceive('isLocked')->andReturn(false);

        $mockRecord = $this->createMockRecord();
        $service->shouldReceive('createRecord')->andReturn($mockRecord);
        $service->shouldReceive('saveRecord')->andReturn(true);

        $data = $this->makeSlotData(); // No quantity key
        $service->createLock($data);

        $this->assertEquals(1, $mockRecord->quantity);
    }

    public function testCreateLockPassesQuantityAndCapacityToIsLocked(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('deleteExpiredRecords')->andReturn(0);
        $service->shouldReceive('isLocked')
            ->once()
            ->with('2025-06-15', '09:00', 1, null, '10:00', null, null, 2, 5)
            ->andReturn(false);

        $mockRecord = $this->createMockRecord();
        $service->shouldReceive('createRecord')->andReturn($mockRecord);
        $service->shouldReceive('saveRecord')->andReturn(true);

        $data = $this->makeSlotData(['quantity' => 2, 'capacity' => 5]);
        $token = $service->createLock($data);

        $this->assertIsString($token);
    }

    public function testCreateLockPassesNullCapacityWhenNotProvided(): void
    {
        $service = $this->makePartialService();
        $service->shouldReceive('deleteExpiredRecords')->andReturn(0);
        $service->shouldReceive('isLocked')
            ->once()
            ->with('2025-06-15', '09:00', 1, null, '10:00', null, null, 1, null)
            ->andReturn(false);

        $mockRecord = $this->createMockRecord();
        $service->shouldReceive('createRecord')->andReturn($mockRecord);
        $service->shouldReceive('saveRecord')->andReturn(true);

        $data = $this->makeSlotData(); // No capacity
        $token = $service->createLock($data);

        $this->assertIsString($token);
    }

    // =========================================================================
    // isLocked — null employeeId must check ALL locks
    // =========================================================================

    public function testIsLockedWithNullEmployeeIdChecksAllLocks(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3) . '/src/services/SoftLockService.php'
        );
        // When employeeId is null, must NOT restrict to only employee-less locks
        $this->assertStringNotContainsString(
            "\$query->andWhere(['employeeId' => null])",
            $source,
            'isLocked with null employeeId must not restrict to only employee-less locks'
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A UTC datetime string (as stored on the record) offset from now, e.g. '-40 minutes'.
     */
    private function utc(string $modify): string
    {
        return (new \DateTime('now', new \DateTimeZone('UTC')))
            ->modify($modify)
            ->format('Y-m-d H:i:s');
    }

    private function makeSlotData(array $overrides = []): array
    {
        return array_merge([
            'serviceId' => 1,
            'employeeId' => null,
            'locationId' => null,
            'date' => '2025-06-15',
            'startTime' => '09:00',
            'endTime' => '10:00',
        ], $overrides);
    }

    /**
     * Create a simple stdClass that acts as a record (property bag)
     */
    private function createMockRecord(): object
    {
        return new class() {
            public ?string $token = null;
            public ?string $sessionHash = null;
            public ?int $serviceId = null;
            public ?int $employeeId = null;
            public ?int $locationId = null;
            public ?string $date = null;
            public ?string $startTime = null;
            public ?string $endTime = null;
            public ?string $endDate = null;
            public int $quantity = 1;
            public ?string $expiresAt = null;
            public ?string $dateCreated = null;
        };
    }
}

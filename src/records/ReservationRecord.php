<?php

namespace anvildev\slots\records;

use Craft;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $userName
 * @property string $userEmail
 * @property string|null $userPhone
 * @property int|null $userId
 * @property string|null $userTimezone
 * @property string $bookingDate
 * @property string|null $endDate
 * @property string|null $startTime
 * @property string|null $endTime
 * @property string $status
 * @property string|null $notes
 * @property string|null $sessionNotes
 * @property bool $notificationSent
 * @property string $confirmationToken
 * @property int|null $employeeId
 * @property int|null $locationId
 * @property int|null $serviceId
 * @property int|null $siteId
 * @property int $quantity
 * @property bool $emailReminder24hSent
 * @property bool $emailReminder1hSent
 * @property string|null $activeSlotKey
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class ReservationRecord extends ActiveRecord
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    public static function tableName(): string
    {
        return '{{%slots_reservations}}';
    }

    public function rules(): array
    {
        return [
            [['userName', 'userEmail', 'bookingDate', 'startTime', 'endTime', 'confirmationToken'], 'required'],
            [['userEmail'], 'email'],
            [['userName', 'userEmail', 'userPhone'], 'string', 'max' => 255],
            [['userTimezone'], 'string', 'max' => 50],
            [['bookingDate'], 'date', 'format' => 'php:Y-m-d'],
            [['startTime', 'endTime'], 'match', 'pattern' => '/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/'],
            [['status'], 'in', 'range' => [self::STATUS_PENDING, self::STATUS_CONFIRMED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW]],
            [['notes', 'sessionNotes'], 'string'],
            [['notificationSent', 'emailReminder24hSent', 'emailReminder1hSent'], 'boolean'],
            [['confirmationToken'], 'string', 'max' => 64],
            [['confirmationToken'], 'unique'],
            [['status'], 'default', 'value' => self::STATUS_CONFIRMED],
            [['notificationSent'], 'default', 'value' => false],
            [['employeeId', 'locationId', 'serviceId', 'siteId', 'quantity', 'userId'], 'integer'],
            [['quantity'], 'default', 'value' => 1],
        ];
    }

    /**
     * Computes activeSlotKey for the unique double-booking constraint.
     * Active employee bookings get a non-NULL key; cancelled and employee-less bookings get NULL.
     */
    public function beforeSave($insert): bool
    {
        $this->activeSlotKey = self::computeSlotKey(
            $this->status,
            $this->employeeId === null ? null : (int)$this->employeeId,
            (string)$this->bookingDate,
            (string)$this->startTime,
        );

        return parent::beforeSave($insert);
    }

    /**
     * The value behind the unique index that stops one staff member being
     * double-booked. Null means "does not hold a slot": a cancelled booking has
     * released it, and a booking with no staff member is limited by the slot's
     * capacity instead.
     *
     * The time is trimmed to H:i because one slot arrives in two forms — "15:00"
     * from the booking form, "15:00:00" once read back out of the TIME column.
     * Left unnormalised the two produce different keys for the same slot, and
     * the index sees no conflict between them.
     */
    public static function computeSlotKey(
        ?string $status,
        ?int $employeeId,
        string $bookingDate,
        string $startTime,
    ): ?string {
        if ($status === self::STATUS_CANCELLED || $employeeId === null) {
            return null;
        }

        return $bookingDate . '|' . substr($startTime, 0, 5) . '|' . $employeeId;
    }

    /**
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        if (class_exists(Craft::class)) {
            return [
                self::STATUS_PENDING => Craft::t('slots', 'status.pending'),
                self::STATUS_CONFIRMED => Craft::t('slots', 'status.confirmed'),
                self::STATUS_CANCELLED => Craft::t('slots', 'status.cancelled'),
                self::STATUS_NO_SHOW => Craft::t('slots', 'status.noShow'),
            ];
        }

        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_CONFIRMED => 'Confirmed',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_NO_SHOW => 'No Show',
        ];
    }

    public static function generateConfirmationToken(): string
    {
        $maxAttempts = 10;
        $attempt = 0;
        do {
            $token = bin2hex(random_bytes(32));
            $attempt++;
        } while (self::find()->where(['confirmationToken' => $token])->exists() && $attempt < $maxAttempts);

        if ($attempt >= $maxAttempts) {
            throw new \RuntimeException('Failed to generate unique confirmation token');
        }

        return $token;
    }
}

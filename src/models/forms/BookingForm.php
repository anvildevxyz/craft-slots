<?php

namespace anvildev\slots\models\forms;

use anvildev\slots\helpers\ValidationHelper;
use craft\base\Model;
use Yii;

class BookingForm extends Model
{
    public ?string $userName = null;
    public ?string $userEmail = null;
    public ?string $userPhone = null;
    public ?string $userTimezone = null;
    public ?string $bookingDate = null;
    public ?string $startTime = null;
    public ?string $endTime = null;
    public ?string $notes = null;
    public ?int $serviceId = null;
    public ?int $employeeId = null;
    public ?int $locationId = null;
    public int $quantity = 1;
    public ?string $honeypot = null;
    public ?string $captchaToken = null;

    public function rules(): array
    {
        return [
            [['userName', 'userEmail'], 'required', 'message' => Yii::t('slots', 'This field is required.')],
            [['bookingDate', 'serviceId'], 'required', 'message' => Yii::t('slots', 'This field is required.'), ],
            [['startTime', 'endTime'], 'required', 'message' => Yii::t('slots', 'This field is required.'), ],
            [['userName', 'userEmail', 'userPhone', 'notes'], 'filter', 'filter' => fn($value) =>
                $value ? trim(strip_tags($value)) : null, ],
            ['userEmail', 'filter', 'filter' => 'strtolower'],
            ['userEmail', 'email', 'message' => Yii::t('slots', 'Please enter a valid email address.')],
            [['userName', 'userEmail', 'userPhone'], 'string', 'max' => 255],
            ['notes', 'string', 'max' => 5000],
            [['bookingDate'], ValidationHelper::DATE_VALIDATOR, 'format' => ValidationHelper::DATE_FORMAT, 'when' => fn($model) => $model->bookingDate !== null],
            [['startTime', 'endTime'], 'match', 'pattern' => ValidationHelper::TIME_FORMAT_PATTERN, 'when' => fn($model) => $model->startTime !== null],
            [['quantity'], 'integer', 'min' => 1, 'max' => 10000],
            [['userTimezone'], 'string', 'max' => 50],
            [['userTimezone'], 'validateTimezone'],
            [['honeypot', 'captchaToken'], 'string'],
            [['userPhone'], 'validatePhone'],
        ];
    }

    public function validateTimezone(string $attribute, ?array $params = null): void
    {
        if (!empty($this->$attribute) && !in_array($this->$attribute, \DateTimeZone::listIdentifiers(), true)) {
            $this->addError($attribute, Yii::t('slots', 'validation.invalidTimezone'));
        }
    }

    public function validatePhone(string $attribute, ?array $params = null): void
    {
        $phone = $this->$attribute;
        if ($phone !== null && $phone !== '' && preg_match('/[a-zA-Z]/', $phone)) {
            $this->addError($attribute, Yii::t('slots', 'validation.invalidPhone'));
        }
    }

    public function isSpam(): bool
    {
        return !empty($this->honeypot);
    }

    public function getReservationData(): array
    {
        return [
            'userName' => $this->userName,
            'userEmail' => $this->userEmail,
            'userPhone' => $this->userPhone,
            'userTimezone' => $this->userTimezone,
            'bookingDate' => $this->bookingDate,
            'startTime' => $this->startTime,
            'endTime' => $this->endTime,
            'serviceId' => $this->serviceId,
            'employeeId' => $this->employeeId,
            'locationId' => $this->locationId,
            'notes' => $this->notes,
            'quantity' => $this->quantity,
        ];
    }
}

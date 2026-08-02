<?php

namespace anvildev\slots\elements;

use anvildev\slots\contracts\ReservationInterface;
use anvildev\slots\elements\db\ReservationQuery;
use anvildev\slots\helpers\DateHelper;
use anvildev\slots\helpers\ElementQueryHelper;
use anvildev\slots\helpers\ValidationHelper;
use anvildev\slots\records\ReservationRecord;
use anvildev\slots\Slots;
use anvildev\slots\traits\HasCancellationPolicy;
use anvildev\slots\traits\HasFormattedDateTime;
use anvildev\slots\traits\HasReservationComputations;
use anvildev\slots\traits\ValidatesTimeRange;
use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Html;
use craft\helpers\UrlHelper;

class Reservation extends Element implements ReservationInterface
{
    use HasCancellationPolicy;
    use HasFormattedDateTime;
    use HasReservationComputations;
    use ValidatesTimeRange;

    public string $userName = '';
    public string $userEmail = '';
    public ?string $userPhone = null;
    public ?int $userId = null;
    public ?string $userTimezone = null;
    public string $bookingDate = '';
    public ?string $startTime = '';
    public ?string $endTime = '';
    public string $status = ReservationRecord::STATUS_CONFIRMED;
    public ?string $notes = null;
    public ?string $sessionNotes = null;
    public bool $notificationSent = false;
    public bool $emailReminder24hSent = false;
    public bool $emailReminder1hSent = false;
    public string $confirmationToken = '';
    public ?int $employeeId = null;
    public ?int $locationId = null;
    public ?int $serviceId = null;
    public int $quantity = 1;
    public float $totalPrice = 0.0;

    /**
     * The site the booking originated from (may differ from the element's siteId
     * for bookings taken on a non-primary site).
     */
    public ?int $bookingSiteId = null;

    private ?Service $_service = null;
    private ?Employee $_employee = null;
    private ?Location $_location = null;

    // ReservationInterface property getters
    public function getId(): ?int
    {
        return $this->id;
    }
    public function getUid(): ?string
    {
        return $this->uid;
    }
    public function getSiteId(): ?int
    {
        return $this->bookingSiteId ?? $this->siteId;
    }
    public function getUserName(): string
    {
        return $this->userName;
    }
    public function getUserEmail(): string
    {
        return $this->userEmail;
    }
    public function getUserPhone(): ?string
    {
        return $this->userPhone;
    }
    public function getUserId(): ?int
    {
        return $this->userId;
    }
    public function getUserTimezone(): ?string
    {
        return $this->userTimezone;
    }
    public function customerEmail(): string
    {
        return $this->userEmail;
    }
    public function customerName(): string
    {
        return $this->userName;
    }
    public function getBookingDate(): string
    {
        return $this->bookingDate;
    }
    public function getStartTime(): string
    {
        return $this->startTime ?? '';
    }
    public function getEndTime(): string
    {
        return $this->endTime ?? '';
    }
    public function getNotes(): ?string
    {
        return $this->notes;
    }
    public function getSessionNotes(): ?string
    {
        return $this->sessionNotes;
    }
    public function getQuantity(): int
    {
        return $this->quantity;
    }
    public function getConfirmationToken(): string
    {
        return $this->confirmationToken;
    }
    public function getNotificationSent(): bool
    {
        return $this->notificationSent;
    }
    public function getEmailReminder24hSent(): bool
    {
        return $this->emailReminder24hSent;
    }
    public function getEmailReminder1hSent(): bool
    {
        return $this->emailReminder1hSent;
    }
    public function getEmployeeId(): ?int
    {
        return $this->employeeId;
    }
    public function getLocationId(): ?int
    {
        return $this->locationId;
    }
    public function getServiceId(): ?int
    {
        return $this->serviceId;
    }
    public function getDateCreated(): ?\DateTime
    {
        return $this->dateCreated;
    }
    public function getDateUpdated(): ?\DateTime
    {
        return $this->dateUpdated;
    }

    // Static metadata
    public static function displayName(): string
    {
        return Craft::t('slots', 'element.reservation');
    }
    public static function lowerDisplayName(): string
    {
        return Craft::t('slots', 'element.reservationLower');
    }
    public static function pluralDisplayName(): string
    {
        return Craft::t('slots', 'element.reservations');
    }
    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('slots', 'element.reservationsLower');
    }
    public static function refHandle(): ?string
    {
        return 'reservation';
    }
    public static function createCondition(): \craft\elements\conditions\ElementConditionInterface
    {
        return \Craft::createObject(conditions\ReservationCondition::class, [static::class]);
    }

    public static function hasTitles(): bool
    {
        return false;
    }
    public static function hasStatuses(): bool
    {
        return true;
    }
    public static function statuses(): array
    {
        return [
            'confirmed' => ['label' => Craft::t('slots', 'status.confirmed'), 'color' => Color::Green],
            'pending' => ['label' => Craft::t('slots', 'status.pending'), 'color' => Color::Orange],
            'cancelled' => ['label' => Craft::t('slots', 'status.cancelled')],
            'no_show' => ['label' => Craft::t('slots', 'status.noShow'), 'color' => Color::Red],
        ];
    }

    public static function eagerLoadingMap(array $sourceElements, string $handle): array|null|false
    {
        $propMap = [
            'service' => ['prop' => 'serviceId', 'type' => Service::class],
            'employee' => ['prop' => 'employeeId', 'type' => Employee::class],
            'location' => ['prop' => 'locationId', 'type' => Location::class],
        ];

        if (isset($propMap[$handle])) {
            $prop = $propMap[$handle]['prop'];
            $map = [];
            foreach ($sourceElements as $el) {
                if ($el->$prop) {
                    $map[] = ['source' => $el->id, 'target' => $el->$prop];
                }
            }

            return ['elementType' => $propMap[$handle]['type'], 'map' => $map];
        }

        return parent::eagerLoadingMap($sourceElements, $handle);
    }

    public function setEagerLoadedElements(string $handle, array $elements, \craft\elements\db\EagerLoadPlan $plan): void
    {
        switch ($handle) {
            case 'service':
                /** @var Service|null $service */
                $service = $elements[0] ?? null;
                $this->_service = $service;
                break;
            case 'employee':
                /** @var Employee|null $employee */
                $employee = $elements[0] ?? null;
                $this->_employee = $employee;
                break;
            case 'location':
                /** @var Location|null $location */
                $location = $elements[0] ?? null;
                $this->_location = $location;
                break;
            default:
                parent::setEagerLoadedElements($handle, $elements, $plan);
        }
    }

    /** @return ReservationQuery */
    public static function find(): ReservationQuery
    {
        return new ReservationQuery(static::class);
    }

    protected function getRecord(): ?ReservationRecord
    {
        return ReservationRecord::findOne($this->id);
    }
    protected function getSettings(): \anvildev\slots\models\Settings
    {
        return \anvildev\slots\models\Settings::loadSettings();
    }

    protected static function defineActions(?string $source = null): array
    {
        return [
            actions\MarkAsNoShow::class,
            Delete::class,
        ];
    }

    protected static function defineExporters(string $source): array
    {
        $exporters = parent::defineExporters($source);
        $exporters[] = exporters\ReservationCsvExporter::class;

        return $exporters;
    }


    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('slots', 'element.allReservations'),
                'defaultSort' => ['bookingDate', 'desc'],
                'type' => 'native',
            ],
            [
                'heading' => Craft::t('slots', 'labels.status'),
            ],
            [
                'key' => 'confirmed',
                'label' => Craft::t('slots', 'status.confirmed'),
                'criteria' => ['reservationStatus' => ReservationRecord::STATUS_CONFIRMED],
                'defaultSort' => ['bookingDate', 'desc'],
                'type' => 'native',
            ],
            [
                'key' => 'pending',
                'label' => Craft::t('slots', 'status.pending'),
                'criteria' => ['reservationStatus' => ReservationRecord::STATUS_PENDING],
                'defaultSort' => ['bookingDate', 'desc'],
                'type' => 'native',
            ],
            [
                'key' => 'cancelled',
                'label' => Craft::t('slots', 'status.cancelled'),
                'criteria' => ['reservationStatus' => ReservationRecord::STATUS_CANCELLED],
                'defaultSort' => ['bookingDate', 'desc'],
                'type' => 'native',
            ],
            [
                'key' => 'no_show',
                'label' => Craft::t('slots', 'status.noShow'),
                'criteria' => ['reservationStatus' => ReservationRecord::STATUS_NO_SHOW],
                'defaultSort' => ['bookingDate', 'desc'],
                'type' => 'native',
            ],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'id' => ['label' => Craft::t('slots', 'reservation.id')],
            'userName' => ['label' => Craft::t('slots', 'reservation.name')],
            'userEmail' => ['label' => Craft::t('slots', 'reservation.email')],
            'serviceName' => ['label' => Craft::t('slots', 'reservation.service')],
            'bookingDate' => ['label' => Craft::t('slots', 'reservation.dateTime')],
            'quantity' => ['label' => Craft::t('slots', 'reservation.seats')],
            'duration' => ['label' => Craft::t('slots', 'reservation.duration')],
            'dateCreated' => ['label' => Craft::t('slots', 'reservation.created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['id', 'userName', 'userEmail', 'serviceName', 'bookingDate', 'quantity', 'duration', 'dateCreated'];
    }

    protected static function defineSearchableAttributes(): array
    {
        // bookingDate is indexed so typing a date still finds bookings, the way
        // the old hand-rolled index allowed. Filtering by a date range is the
        // condition rule's job — this is only for the search box.
        return ['userName', 'userEmail', 'userPhone', 'notes', 'bookingDate'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            [
                'label' => Craft::t('slots', 'reservation.name'),
                'orderBy' => 'slots_reservations.userName',
                'attribute' => 'userName',
            ],
            [
                'label' => Craft::t('slots', 'reservation.email'),
                'orderBy' => 'slots_reservations.userEmail',
                'attribute' => 'userEmail',
            ],
            [
                'label' => Craft::t('slots', 'reservation.sortBookingDate'),
                'orderBy' => 'slots_reservations.bookingDate',
                'attribute' => 'bookingDate',
            ],
            [
                'label' => Craft::t('slots', 'reservation.created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
            ],
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        $url = $this->getCpEditUrl();
        return match ($attribute) {
            'id' => $url ? Html::a('#' . $this->id, $url) : '#' . $this->id,
            'userName' => Html::encode($this->userName),
            'userEmail' => Html::encode($this->userEmail),
            'serviceName' => ($svc = $this->getService()) ? Html::encode($svc->title) : Html::tag('span', '-', ['class' => 'light']),
            'bookingDate' => Html::tag('div',
                    Html::tag('strong', Craft::$app->formatter->asDate($this->bookingDate, 'short')) .
                    Html::tag('br') .
                    Html::tag('span', $this->startTime . ' - ' . $this->endTime, ['class' => 'light', 'style' => 'font-size: 11px;'])
                ),
            'quantity' => ($qty = $this->quantity ?? 1) > 1
                ? Html::tag('span', $qty . 'x', ['class' => 'badge', 'style' => 'background-color: #0d78f2; color: white;'])
                : Html::tag('span', (string)$qty, ['class' => 'light']),
            'duration' => Html::tag('span', $this->getDurationMinutes() . ' Min.', ['class' => 'light']),
            default => parent::attributeHtml($attribute),
        };
    }

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['userName', 'userEmail', 'bookingDate'], 'required'],
            [['startTime', 'endTime'], 'required'],
            [['userEmail'], 'email'],
            [['userName', 'userEmail', 'userPhone'], 'string', 'max' => 255],
            [['userTimezone'], 'string', 'max' => 50],
            [['bookingDate'], ValidationHelper::DATE_VALIDATOR, 'format' => ValidationHelper::DATE_FORMAT],
            [['startTime', 'endTime'], 'match', 'pattern' => ValidationHelper::TIME_FORMAT_PATTERN],
            [['status'], 'in', 'range' => [
                ReservationRecord::STATUS_PENDING,
                ReservationRecord::STATUS_CONFIRMED,
                ReservationRecord::STATUS_CANCELLED,
                ReservationRecord::STATUS_NO_SHOW,
            ]],
            [['notes', 'sessionNotes'], 'string'],
            [['notificationSent', 'emailReminder24hSent', 'emailReminder1hSent'], 'boolean'],
            [['confirmationToken'], 'string', 'max' => 64],
            [['quantity'], 'integer', 'min' => 1],
            [['quantity'], 'required'],
            [['quantity'], 'default', 'value' => 1],
            [['userId'], 'integer'],
            // Custom validation: Employee-Location consistency
            ['locationId', 'validateEmployeeAndLocationExist'],
        ]);
    }

    protected function validateBookingDate(): void
    {
        if (!$this->id && $this->bookingDate && $this->startTime) {
            $bookingDateTime = DateHelper::parseDateTime($this->bookingDate, $this->startTime);
            $now = new \DateTime();

            if (!$bookingDateTime) {
                return; // Invalid date/time format, let other validators handle it
            }

            // Check if booking is in the past
            if ($bookingDateTime->getTimestamp() < $now->getTimestamp()) {
                $this->addError('bookingDate', Craft::t('slots', 'validation.pastBookingNotAllowed'));
                return;
            }

            // Check minimum advance booking time
            $settings = $this->getSettings();
            $minimumAdvanceHours = $settings->minimumAdvanceBookingHours ?? 2;

            // If set to 0, allow immediate bookings
            if ($minimumAdvanceHours > 0) {
                $minimumBookingTime = clone $now;
                $minimumBookingTime->modify("+{$minimumAdvanceHours} hours");

                if ($bookingDateTime->getTimestamp() < $minimumBookingTime->getTimestamp()) {
                    $hoursText = $minimumAdvanceHours === 1
                        ? Craft::t('slots', 'labels.hour')
                        : Craft::t('slots', 'labels.hours');
                    $this->addError('bookingDate', Craft::t('slots', 'validation.minimumAdvanceBooking', [
                        'hours' => $minimumAdvanceHours,
                        'hoursText' => $hoursText,
                    ]));
                }
            }
        }
    }

    protected function validateQuantity(): void
    {
        if ($this->quantity < 1) {
            $this->addError('quantity', Craft::t('slots', 'validation.quantityMinimum'));
        }
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function init(): void
    {
        parent::init();
        if (empty($this->userTimezone)) {
            $this->userTimezone = Craft::$app->getTimeZone();
        }
    }

    public function __toString(): string
    {
        if ($this->userName && $this->bookingDate) {
            return "$this->userName — $this->bookingDate";
        }

        return $this->userName ?: parent::__toString();
    }

    public function canDuplicate(User $user): bool
    {
        return false;
    }

    protected function cpEditUrl(): ?string
    {
        return 'slots/bookings/' . $this->id;
    }

    public function canView(User $user): bool
    {
        if ($user->admin) {
            return true;
        }
        if (!$user->can('slots-viewBookings')) {
            return false;
        }
        // Staff scoping: can only view bookings for their linked employees
        if (!$user->can('slots-manageBookings')) {
            $employees = Slots::getInstance()->getPermission()->getEmployeesForUser($user->id);
            if ($employees && !in_array($this->employeeId, array_map(fn($e) => $e->id, $employees), true)) {
                return false;
            }
        }
        return true;
    }

    public function canSave(User $user): bool
    {
        return $user->admin || $user->can('slots-manageBookings');
    }

    public function canDelete(User $user): bool
    {
        return $user->admin || $user->can('slots-manageBookings');
    }

    /** Validate employee and location exist when both IDs are set */
    public function validateEmployeeAndLocationExist($attribute, $params): void
    {
        if (!$this->employeeId || !$this->locationId) {
            return;
        }
        if (!Employee::find()->id($this->employeeId)->siteId('*')->exists()) {
            $this->addError('employeeId', Craft::t('slots', 'reservation.employeeNotExist'));
        }
        if (!Location::find()->id($this->locationId)->siteId('*')->exists()) {
            $this->addError('locationId', Craft::t('slots', 'reservation.locationNotExist'));
        }
    }

    public function extraFields(): array
    {
        return [
            'totalPrice' => 'getTotalPrice',
            'totalDuration' => 'getTotalDuration',
        ];
    }

    public function beforeValidate(): bool
    {
        if (!parent::beforeValidate()) {
            return false;
        }
        $this->validateTimeRange();
        $this->validateBookingDate();
        $this->validateQuantity();
        return true;
    }

    public function afterSave(bool $isNew): void
    {
        $wasCancelled = false;
        $quantityReduced = false;

        if (!$isNew) {
            $record = $this->getRecord();
            if (!$record) {
                throw new \Exception('Invalid reservation ID: ' . $this->id);
            }

            // Detect status transition to cancelled
            if ($record->status !== ReservationRecord::STATUS_CANCELLED
                && $this->status === ReservationRecord::STATUS_CANCELLED) {
                $wasCancelled = true;
            }

            // Detect quantity reduction (frees up capacity)
            if ((int)$record->quantity > (int)$this->quantity) {
                $quantityReduced = true;
            }
        } else {
            // Check if a row already exists (e.g. from batch-inserted seed data
            // that has no matching elements entry). If so, update it instead of inserting.
            $record = ReservationRecord::findOne($this->id) ?? new ReservationRecord();
            $record->id = (int)$this->id;

            // Generate confirmation token for new reservations
            if (empty($this->confirmationToken)) {
                $this->confirmationToken = ReservationRecord::generateConfirmationToken();
            }
        }

        $record->userName = $this->userName;
        $record->userEmail = $this->userEmail;
        $record->userPhone = $this->userPhone;
        $record->userId = $this->userId;
        $record->userTimezone = $this->userTimezone ?? Craft::$app->getTimeZone();

        // Store times directly in the configured timezone (no conversion)
        $record->bookingDate = $this->bookingDate;
        $record->startTime = $this->startTime;
        $record->endTime = $this->endTime;

        $record->status = $this->status;
        $record->notes = $this->notes;
        $record->sessionNotes = $this->sessionNotes;
        $record->notificationSent = $this->notificationSent;
        $record->emailReminder24hSent = $this->emailReminder24hSent;
        $record->emailReminder1hSent = $this->emailReminder1hSent;
        $record->confirmationToken = $this->confirmationToken;
        $record->employeeId = $this->employeeId;
        $record->locationId = $this->locationId;
        $record->serviceId = $this->serviceId;
        $record->quantity = $this->quantity;
        $record->siteId = $this->bookingSiteId ?? $this->siteId;

        try {
            $record->save(false);
        } catch (\yii\db\IntegrityException $e) {
            $message = $e->getMessage();
            if (str_contains($message, 'activeSlotKey') || str_contains($message, 'Duplicate entry')) {
                throw new \anvildev\slots\exceptions\BookingConflictException(
                    Craft::t('slots', 'booking.slotAlreadyBooked')
                );
            }

            Craft::error('Reservation save failed with IntegrityException: ' . $message, __METHOD__);
            throw $e;
        }


        parent::afterSave($isNew);
    }

    /**
     * Craft soft-deletes by default, and the control panel's Delete action does
     * exactly that. Deleting the row here destroyed the booking while leaving a
     * trashed element behind, so restoring it produced an empty reservation.
     *
     * A hard delete needs no help: slots_reservations.id is a foreign key onto
     * elements.id with ON DELETE CASCADE, so the row goes with the element.
     *
     * What a soft delete must do is release the slot. `activeSlotKey` carries a
     * unique index to stop double-booking, and a booking sitting in the trash
     * should not keep a seat reserved against everyone else.
     */
    public function afterDelete(): void
    {
        if (!$this->hardDelete) {
            \Craft::$app->getDb()->createCommand()
                ->update(ReservationRecord::tableName(), ['activeSlotKey' => null], ['id' => $this->id])
                ->execute();
        }

        parent::afterDelete();
    }

    /**
     * Reclaim the slot the booking held before it was trashed.
     *
     * Re-saving the record recomputes activeSlotKey from the booking's own date,
     * time and employee. If someone has taken that slot in the meantime the
     * unique index refuses it — the booking still comes back, just without the
     * reservation on that slot, which is the honest outcome and visible to
     * whoever restores it.
     */
    public function afterRestore(): void
    {
        $record = $this->getRecord();

        if ($record) {
            try {
                $record->save(false);
            } catch (\yii\db\IntegrityException $e) {
                \Craft::warning(
                    "Restored reservation {$this->id} could not reclaim its slot — it is taken: " . $e->getMessage(),
                    __METHOD__,
                );
            }
        }

        parent::afterRestore();
    }

    public static function getStatuses(): array
    {
        return ReservationRecord::getStatuses();
    }
    public function getStatusLabel(): string
    {
        return self::getStatuses()[$this->status] ?? 'Unknown';
    }

    public function cancel(): bool
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $this->status = ReservationRecord::STATUS_CANCELLED;
        return Craft::$app->elements->saveElement($this);
    }

    public function markAsNoShow(): bool
    {
        if ($this->status === ReservationRecord::STATUS_CANCELLED) {
            return false;
        }
        if ($this->status === ReservationRecord::STATUS_NO_SHOW) {
            return false;
        }

        $this->status = ReservationRecord::STATUS_NO_SHOW;
        return Craft::$app->elements->saveElement($this);
    }

    public function getManagementUrl(): string
    {
        return UrlHelper::siteUrl('booking/manage/' . $this->confirmationToken, null, null, $this->siteId ?? Craft::$app->getSites()->getPrimarySite()->id);
    }
    public function getCancelUrl(): string
    {
        return UrlHelper::siteUrl('booking/cancel/' . $this->confirmationToken, null, null, $this->siteId ?? Craft::$app->getSites()->getPrimarySite()->id);
    }
    public function getIcsUrl(): string
    {
        return UrlHelper::siteUrl('booking/ics/' . $this->confirmationToken, null, null, $this->siteId ?? Craft::$app->getSites()->getPrimarySite()->id);
    }

    public function getBookingDateTime(): ?\DateTime
    {
        if (empty($this->bookingDate) || empty($this->startTime)) {
            return null;
        }

        try {
            $dateTimeString = $this->bookingDate . ' ' . $this->startTime;
            $tz = new \DateTimeZone($this->userTimezone ?: Craft::$app->getTimeZone());
            $dateTime = \DateTime::createFromFormat('Y-m-d H:i:s', $dateTimeString, $tz);
            if (!$dateTime) {
                $dateTime = \DateTime::createFromFormat('Y-m-d H:i', $dateTimeString, $tz);
            }

            return $dateTime;
        } catch (\Exception $e) {
            \Craft::error('Failed to create DateTime: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    public function getEmployee(): ?Employee
    {
        if ($this->_employee === null && $this->employeeId !== null) {
            $this->_employee = Employee::find()->id($this->employeeId)->siteId('*')->one();
        }
        return $this->_employee;
    }

    public function getUser(): ?User
    {
        return $this->userId !== null ? User::find()->id($this->userId)->one() : null;
    }

    public function getService(): ?Service
    {
        if ($this->_service === null && $this->serviceId !== null) {
            /** @var Service|null $service */
            $service = ElementQueryHelper::forAllSites(Service::find()->id($this->serviceId))->one();
            $this->_service = $service;
        }
        return $this->_service;
    }

    public function getLocation(): ?Location
    {
        if ($this->_location === null && $this->locationId !== null) {
            $this->_location = Location::find()->id($this->locationId)->siteId('*')->one();
        }
        return $this->_location;
    }

    public function recalculateTotals(): void
    {
        $this->totalPrice = $this->getTotalPrice();
    }

    public function getTotalDuration(): int
    {
        return $this->getDurationMinutes();
    }

    public static function findByToken(string $token): ?self
    {
        if ($token === '') {
            return null;
        }

        return self::find()
            ->confirmationToken($token)
            ->one();
    }

    // ReservationInterface persistence
    public function save(bool $runValidation = true): bool
    {
        return Craft::$app->elements->saveElement($this, $runValidation);
    }
    public function delete(): bool
    {
        return Craft::$app->elements->deleteElement($this);
    }
}

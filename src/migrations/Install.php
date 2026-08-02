<?php

namespace anvildev\slots\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists('{{%slots_settings}}')) {
            $this->createTable('{{%slots_settings}}', [
                'id' => $this->primaryKey(),
                'defaultCurrency' => $this->string(4)->null(),
                'softLockDurationMinutes' => $this->integer()->notNull()->defaultValue(5),
                'minimumAdvanceBookingHours' => $this->integer()->notNull()->defaultValue(0),
                'maximumAdvanceBookingDays' => $this->integer()->notNull()->defaultValue(90),
                'cancellationPolicyHours' => $this->integer()->notNull()->defaultValue(24),
                'enableRateLimiting' => $this->boolean()->notNull()->defaultValue(true),
                'rateLimitPerEmail' => $this->integer()->notNull()->defaultValue(5),
                'rateLimitPerIp' => $this->integer()->notNull()->defaultValue(10),
                'enableCaptcha' => $this->boolean()->notNull()->defaultValue(false),
                'captchaProvider' => $this->string(20)->null(),
                'recaptchaSiteKey' => $this->string(255)->null(),
                'recaptchaSecretKey' => $this->string(255)->null(),
                'hcaptchaSiteKey' => $this->string(255)->null(),
                'hcaptchaSecretKey' => $this->string(255)->null(),
                'turnstileSiteKey' => $this->string(255)->null(),
                'turnstileSecretKey' => $this->string(255)->null(),
                'recaptchaScoreThreshold' => $this->float()->notNull()->defaultValue(0.5),
                'recaptchaAction' => $this->string(100)->notNull()->defaultValue('booking'),
                'enableHoneypot' => $this->boolean()->notNull()->defaultValue(true),
                'honeypotFieldName' => $this->string(50)->notNull()->defaultValue('website'),
                'enableIpBlocking' => $this->boolean()->notNull()->defaultValue(false),
                'blockedIps' => $this->text()->null(),
                'enableTimeBasedLimits' => $this->boolean()->notNull()->defaultValue(true),
                'minimumSubmissionTime' => $this->integer()->notNull()->defaultValue(3),
                'enableAuditLog' => $this->boolean()->notNull()->defaultValue(false),
                'ownerNotificationEnabled' => $this->boolean()->notNull()->defaultValue(true),
                'ownerNotificationSubject' => $this->string(255)->null(),
                'ownerNotificationLanguage' => $this->string(255)->null(),
                'ownerEmail' => $this->string()->null(),
                'ownerName' => $this->string()->null(),
                'bookingConfirmationSubject' => $this->string(),
                'reminderEmailSubject' => $this->string(255)->null(),
                'cancellationEmailSubject' => $this->string(255)->null(),
                'bookingPageUrl' => $this->string(500)->null(),
                'emailRemindersEnabled' => $this->boolean()->notNull()->defaultValue(true),
                'emailReminderHoursBefore' => $this->integer()->notNull()->defaultValue(24),
                'sendCancellationEmail' => $this->boolean()->notNull()->defaultValue(true),
                'paymentMode' => $this->string(20)->null(),
                'stripePublishableKey' => $this->text()->null(),
                'stripeSecretKey' => $this->text()->null(),
                'stripeWebhookSecret' => $this->text()->null(),
                'pendingPaymentTtlMinutes' => $this->integer()->notNull()->defaultValue(30),
                'enableAutoRefund' => $this->boolean()->notNull()->defaultValue(false),
                'defaultRefundTiers' => $this->text()->null(),
                'mutexDriver' => $this->string(10)->notNull()->defaultValue('auto'),
                'defaultTimeSlotLength' => $this->integer()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $now = date('Y-m-d H:i:s');
            $this->insert('{{%slots_settings}}', [
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => \craft\helpers\StringHelper::UUID(),
            ]);
        }

        if (!$this->db->tableExists('{{%slots_locations}}')) {
            $this->createTable('{{%slots_locations}}', [
                'id' => $this->primaryKey(),
                'timezone' => $this->string(50)->null(),
                'addressLine1' => $this->string()->null(),
                'addressLine2' => $this->string()->null(),
                'locality' => $this->string()->null(),
                'administrativeArea' => $this->string()->null(),
                'postalCode' => $this->string(20)->null(),
                'countryCode' => $this->string(2)->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%slots_locations}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
        }

        if (!$this->db->tableExists('{{%slots_employees}}')) {
            $this->createTable('{{%slots_employees}}', [
                'id' => $this->primaryKey(),
                'userId' => $this->integer()->null(),
                'locationId' => $this->integer()->null(),
                'email' => $this->string(255)->null(),
                'workingHours' => $this->json()->null(),
                'serviceIds' => $this->json()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%slots_employees}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
            $this->addForeignKey(null, '{{%slots_employees}}', 'userId', '{{%users}}', 'id', 'SET NULL', null);
            $this->addForeignKey(null, '{{%slots_employees}}', 'locationId', '{{%elements}}', 'id', 'SET NULL', null);
            $this->createIndex(null, '{{%slots_employees}}', 'userId');
            $this->createIndex(null, '{{%slots_employees}}', 'locationId');
        }

        if (!$this->db->tableExists('{{%slots_services}}')) {
            $this->createTable('{{%slots_services}}', [
                'id' => $this->primaryKey(),
                'propagationMethod' => $this->string(50)->notNull()->defaultValue('none'),
                'description' => $this->text()->null(),
                'duration' => $this->integer()->null(),
                'bufferBefore' => $this->integer()->null(),
                'bufferAfter' => $this->integer()->null(),
                'price' => $this->decimal(14, 4)->null(),
                'minTimeBeforeBooking' => $this->integer()->null(),
                'timeSlotLength' => $this->integer()->null(),
                'availabilitySchedule' => $this->text()->null(),
                'customerLimitEnabled' => $this->boolean()->notNull()->defaultValue(false),
                'customerLimitCount' => $this->integer()->null(),
                'customerLimitPeriod' => $this->string(20)->null(),
                'customerLimitPeriodType' => $this->string(20)->null(),
                'taxCategoryId' => $this->integer()->null(),
                'allowCancellation' => $this->boolean()->notNull()->defaultValue(true),
                'cancellationPolicyHours' => $this->integer()->null(),
                'allowRefund' => $this->boolean()->notNull()->defaultValue(true),
                'refundTiers' => $this->text()->null(),
                'deletedAt' => $this->dateTime()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%slots_services}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
        }

        if (!$this->db->tableExists('{{%slots_schedules}}')) {
            $this->createTable('{{%slots_schedules}}', [
                'id' => $this->primaryKey(),
                'workingHours' => $this->json()->notNull(),
                'startDate' => $this->date()->null(),
                'endDate' => $this->date()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%slots_schedules}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
            $this->createIndex(null, '{{%slots_schedules}}', ['startDate', 'endDate']);
        }

        if (!$this->db->tableExists('{{%slots_employee_schedule_assignments}}')) {
            $this->createTable('{{%slots_employee_schedule_assignments}}', [
                'id' => $this->primaryKey(),
                'employeeId' => $this->integer()->notNull(),
                'scheduleId' => $this->integer()->notNull(),
                'sortOrder' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%slots_employee_schedule_assignments}}', ['employeeId', 'scheduleId'], true);
            $this->createIndex(null, '{{%slots_employee_schedule_assignments}}', ['employeeId', 'sortOrder']);
            $this->addForeignKey(null, '{{%slots_employee_schedule_assignments}}', 'employeeId', '{{%elements}}', 'id', 'CASCADE', null);
            $this->addForeignKey(null, '{{%slots_employee_schedule_assignments}}', 'scheduleId', '{{%slots_schedules}}', 'id', 'CASCADE', null);
        }

        if (!$this->db->tableExists('{{%slots_service_schedule_assignments}}')) {
            $this->createTable('{{%slots_service_schedule_assignments}}', [
                'id' => $this->primaryKey(),
                'serviceId' => $this->integer()->notNull(),
                'scheduleId' => $this->integer()->notNull(),
                'sortOrder' => $this->integer()->notNull()->defaultValue(0),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%slots_service_schedule_assignments}}', ['serviceId', 'scheduleId'], true);
            $this->createIndex(null, '{{%slots_service_schedule_assignments}}', ['serviceId', 'sortOrder']);
            $this->addForeignKey(null, '{{%slots_service_schedule_assignments}}', 'serviceId', '{{%slots_services}}', 'id', 'CASCADE', null);
            $this->addForeignKey(null, '{{%slots_service_schedule_assignments}}', 'scheduleId', '{{%slots_schedules}}', 'id', 'CASCADE', null);
        }

        if (!$this->db->tableExists('{{%slots_service_locations}}')) {
            $this->createTable('{{%slots_service_locations}}', [
                'id' => $this->primaryKey(),
                'serviceId' => $this->integer()->notNull(),
                'locationId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%slots_service_locations}}', 'serviceId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%slots_service_locations}}', 'locationId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
            $this->createIndex(null, '{{%slots_service_locations}}', ['serviceId', 'locationId'], true);
        }

        if (!$this->db->tableExists('{{%slots_reservations}}')) {
            $this->createTable('{{%slots_reservations}}', [
                'id' => $this->primaryKey(),
                'userName' => $this->string()->notNull(),
                'userEmail' => $this->string()->notNull(),
                'userPhone' => $this->string(),
                'userId' => $this->integer()->null(),
                'userTimezone' => $this->string(50)->null(),
                'bookingDate' => $this->date()->notNull(),
                'startTime' => $this->time()->null(),
                'endTime' => $this->time()->null(),
                'status' => $this->string(20)->notNull()->defaultValue('confirmed'),
                'activeSlotKey' => $this->string(255)->null(),
                'employeeId' => $this->integer()->null(),
                'locationId' => $this->integer()->null(),
                'serviceId' => $this->integer()->null(),
                'siteId' => $this->integer()->null(),
                'quantity' => $this->integer()->notNull()->defaultValue(1),
                'notes' => $this->text(),
                'sessionNotes' => $this->text(),
                'notificationSent' => $this->boolean()->notNull()->defaultValue(false),
                'emailReminder24hSent' => $this->boolean()->notNull()->defaultValue(false),
                'emailReminder1hSent' => $this->boolean()->notNull()->defaultValue(false),
                'confirmationToken' => $this->string(64)->notNull()->unique(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            // Reservations are standalone ActiveRecords, so no FK to elements.id
            // Reservations are elements: the primary key is the element id.
            $this->addForeignKey(null, '{{%slots_reservations}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
            $this->addForeignKey(null, '{{%slots_reservations}}', 'employeeId', '{{%elements}}', 'id', 'SET NULL', null);
            $this->addForeignKey(null, '{{%slots_reservations}}', 'locationId', '{{%elements}}', 'id', 'SET NULL', null);
            $this->addForeignKey(null, '{{%slots_reservations}}', 'serviceId', '{{%elements}}', 'id', 'SET NULL', null);
            $this->addForeignKey(null, '{{%slots_reservations}}', 'userId', '{{%users}}', 'id', 'SET NULL', 'CASCADE');

            $this->createIndex(null, '{{%slots_reservations}}', ['bookingDate', 'startTime']);
            $this->createIndex(null, '{{%slots_reservations}}', 'userEmail');
            $this->createIndex(null, '{{%slots_reservations}}', 'userId');
            $this->createIndex(null, '{{%slots_reservations}}', 'status');
            $this->createIndex(null, '{{%slots_reservations}}', 'employeeId');
            $this->createIndex(null, '{{%slots_reservations}}', 'locationId');
            $this->createIndex(null, '{{%slots_reservations}}', 'serviceId');
            $this->createIndex('idx_reservations_date_employee_status', '{{%slots_reservations}}', ['bookingDate', 'employeeId', 'status']);
            $this->createIndex('idx_reservations_date_service_status', '{{%slots_reservations}}', ['bookingDate', 'serviceId', 'status']);
            $this->createIndex('idx_confirmationToken', '{{%slots_reservations}}', 'confirmationToken', true);
            // activeSlotKey: "date|time|employeeId" for active bookings, NULL for cancelled/employee-less
            $this->createIndex('idx_unique_active_booking', '{{%slots_reservations}}', 'activeSlotKey', true);
        }


        if (!$this->db->tableExists('{{%slots_blackout_dates}}')) {
            $this->createTable('{{%slots_blackout_dates}}', [
                'id' => $this->primaryKey(),
                'title' => $this->string()->notNull(),
                'startDate' => $this->date()->notNull(),
                'endDate' => $this->date()->notNull(),
                'isActive' => $this->boolean()->notNull()->defaultValue(true),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%slots_blackout_dates}}', 'id', '{{%elements}}', 'id', 'CASCADE', null);
            $this->createIndex(null, '{{%slots_blackout_dates}}', ['startDate', 'endDate']);
            $this->createIndex(null, '{{%slots_blackout_dates}}', 'isActive');
        }

        if (!$this->db->tableExists('{{%slots_blackout_dates_locations}}')) {
            $this->createTable('{{%slots_blackout_dates_locations}}', [
                'id' => $this->primaryKey(),
                'blackoutDateId' => $this->integer()->notNull(),
                'locationId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%slots_blackout_dates_locations}}', 'blackoutDateId', '{{%slots_blackout_dates}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%slots_blackout_dates_locations}}', 'locationId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
            $this->createIndex(null, '{{%slots_blackout_dates_locations}}', ['blackoutDateId', 'locationId'], true);
        }

        if (!$this->db->tableExists('{{%slots_blackout_dates_employees}}')) {
            $this->createTable('{{%slots_blackout_dates_employees}}', [
                'id' => $this->primaryKey(),
                'blackoutDateId' => $this->integer()->notNull(),
                'employeeId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->addForeignKey(null, '{{%slots_blackout_dates_employees}}', 'blackoutDateId', '{{%slots_blackout_dates}}', 'id', 'CASCADE', 'CASCADE');
            $this->addForeignKey(null, '{{%slots_blackout_dates_employees}}', 'employeeId', '{{%elements}}', 'id', 'CASCADE', 'CASCADE');
            $this->createIndex(null, '{{%slots_blackout_dates_employees}}', ['blackoutDateId', 'employeeId'], true);
        }


        if (!$this->db->tableExists('{{%slots_soft_locks}}')) {
            $this->createTable('{{%slots_soft_locks}}', [
                'id' => $this->primaryKey(),
                'token' => $this->string(64)->notNull(),
                'sessionHash' => $this->string(64)->null(),
                'serviceId' => $this->integer()->notNull(),
                'employeeId' => $this->integer()->null(),
                'locationId' => $this->integer()->null(),
                'date' => $this->date()->notNull(),
                'endDate' => $this->date()->null(),
                'startTime' => $this->string(10)->null(),
                'endTime' => $this->string(10)->null(),
                'quantity' => $this->integer()->notNull()->defaultValue(1),
                'expiresAt' => $this->dateTime()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%slots_soft_locks}}', 'token', true);
            $this->createIndex(null, '{{%slots_soft_locks}}', ['date', 'startTime', 'serviceId']);
            $this->createIndex(null, '{{%slots_soft_locks}}', 'expiresAt');
        }

        if (!$this->db->tableExists('{{%slots_payments}}')) {
            $this->createTable('{{%slots_payments}}', [
                'id' => $this->primaryKey(),
                'reservationId' => $this->integer()->notNull(),
                'gateway' => $this->string(64)->notNull(),
                'externalId' => $this->string(255)->null(),
                'status' => $this->string(20)->notNull()->defaultValue('pending'),
                'amount' => $this->integer()->notNull()->defaultValue(0),
                'currency' => $this->string(3)->notNull(),
                'refundedAmount' => $this->integer()->notNull()->defaultValue(0),
                'payload' => $this->text()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, '{{%slots_payments}}', 'reservationId');
            $this->createIndex(null, '{{%slots_payments}}', 'externalId');
            $this->addForeignKey(null, '{{%slots_payments}}', 'reservationId', '{{%slots_reservations}}', 'id', 'CASCADE', null);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $isMySQL = $this->db->getDriverName() === 'mysql';
        if ($isMySQL) {
            $this->execute('SET FOREIGN_KEY_CHECKS = 0');
        }

        foreach ([
            'slots_payments',
            'slots_soft_locks',
            'slots_blackout_dates_employees', 'slots_blackout_dates_locations', 'slots_blackout_dates',
            'slots_reservations',
            'slots_service_locations',
            'slots_service_schedule_assignments', 'slots_employee_schedule_assignments',
            'slots_schedules', 'slots_services', 'slots_employees', 'slots_locations',
            'slots_settings',
        ] as $table) {
            $this->dropTableIfExists("{{%{$table}}}");
        }

        if ($isMySQL) {
            $this->execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        return true;
    }
}

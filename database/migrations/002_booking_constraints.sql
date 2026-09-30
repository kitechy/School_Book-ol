USE school_bookol;

SET @add_time_check = IF(
    EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'bookings'
          AND CONSTRAINT_NAME = 'bookings_valid_time'
    ),
    'SELECT 1',
    'ALTER TABLE bookings ADD CONSTRAINT bookings_valid_time CHECK (end_time > start_time)'
);
PREPARE migration_statement FROM @add_time_check;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

SET @add_people_check = IF(
    EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'bookings'
          AND CONSTRAINT_NAME = 'bookings_valid_people'
    ),
    'SELECT 1',
    'ALTER TABLE bookings ADD CONSTRAINT bookings_valid_people CHECK (people_count IS NULL OR people_count > 0)'
);
PREPARE migration_statement FROM @add_people_check;
EXECUTE migration_statement;
DEALLOCATE PREPARE migration_statement;

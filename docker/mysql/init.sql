-- BD aparte para los tests (los tests de concurrencia la migran desde cero).
CREATE DATABASE IF NOT EXISTS trading_test;
GRANT ALL PRIVILEGES ON trading_test.* TO 'trading'@'%';

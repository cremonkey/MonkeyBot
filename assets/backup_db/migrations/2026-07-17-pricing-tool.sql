-- SPEC-24 — deterministic price calculator. Pricing that the model must never compute
-- (offers, capacity, children) lives here as data; a PHP tool does the arithmetic.
--
-- Why a table and not the prompt: the price guard cannot verify arithmetic (measured 4/8)
-- and the numbers collide across items (11,500 = Double Garden 4-nights AND Royal Suite
-- 1-night), so a correct computed total reads to the judge as another item's price. PHP
-- computes it exactly and the result is injected into the guard's source as authoritative.

CREATE TABLE IF NOT EXISTS `pricing_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `config_json` mediumtext DEFAULT NULL,
  `status` enum('0','1') NOT NULL DEFAULT '1',
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

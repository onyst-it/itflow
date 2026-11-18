CREATE TABLE IF NOT EXISTS `onyst_syncro_contact_map` (
  `source_system` varchar(16) NOT NULL,
  `source_contact_id` varchar(32) NOT NULL,
  `source_customer_id` varchar(32) NOT NULL,
  `client_id` int(11) NOT NULL,
  `contact_id` int(11) DEFAULT NULL,
  `source_snapshot_json` longtext NOT NULL CHECK (json_valid(`source_snapshot_json`)),
  `source_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `imported_fields_json` text NOT NULL CHECK (json_valid(`imported_fields_json`)),
  `destination_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_verified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`source_system`,`source_contact_id`),
  UNIQUE KEY `one_source_per_destination` (`source_system`,`client_id`,`contact_id`),
  KEY `migration_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

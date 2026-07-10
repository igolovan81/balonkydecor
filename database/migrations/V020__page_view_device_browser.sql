ALTER TABLE `page_views`
  ADD COLUMN `device_type` varchar(20) NOT NULL DEFAULT 'other' AFTER `user_agent`,
  ADD COLUMN `browser`     varchar(20) NOT NULL DEFAULT 'other' AFTER `device_type`;

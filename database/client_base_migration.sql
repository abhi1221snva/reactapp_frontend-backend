-- MySQL dump 10.13  Distrib 8.0.20, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: client_1
-- ------------------------------------------------------
-- Server version	8.0.21

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
TRUNCATE TABLE `migrations`;
INSERT INTO `migrations` VALUES (1,'2020_07_30_062211_create_ami_log_table',0),(2,'2020_07_30_062211_create_api_table',0),(3,'2020_07_30_062211_create_api_disposition_table',0),(4,'2020_07_30_062211_create_api_parameter_table',0),(5,'2020_07_30_062211_create_callback_table',0),(6,'2020_07_30_062211_create_campaign_table',0),(7,'2020_07_30_062211_create_campaign_disposition_table',0),(8,'2020_07_30_062211_create_campaign_list_table',0),(9,'2020_07_30_062211_create_cdr_table',0),(10,'2020_07_30_062211_create_client_setting_table',0),(11,'2020_07_30_062211_create_comment_table',0),(12,'2020_07_30_062211_create_conferencing_table',0),(13,'2020_07_30_062211_create_did_table',0),(14,'2020_07_30_062211_create_disposition_table',0),(15,'2020_07_30_062211_create_dnc_table',0),(16,'2020_07_30_062211_create_exclude_number_table',0),(17,'2020_07_30_062211_create_extension_group_table',0),(18,'2020_07_30_062211_create_extension_group_map_table',0),(19,'2020_07_30_062211_create_extension_live_table',0),(20,'2020_07_30_062211_create_fax_table',0),(21,'2020_07_30_062211_create_ip_setting_table',0),(22,'2020_07_30_062211_create_ivr_table',0),(23,'2020_07_30_062211_create_ivr_menu_table',0),(24,'2020_07_30_062211_create_label_table',0),(25,'2020_07_30_062211_create_lead_report_table',0),(26,'2020_07_30_062211_create_lead_temp_table',0),(27,'2020_07_30_062211_create_line_detail_table',0),(28,'2020_07_30_062211_create_list_table',0),(29,'2020_07_30_062211_create_list_data_table',0),(30,'2020_07_30_062211_create_list_header_table',0),(31,'2020_07_30_062211_create_local_channel1_table',0),(32,'2020_07_30_062211_create_mailbox_table',0),(33,'2020_07_30_062211_create_marketting_campaign_table',0),(34,'2020_07_30_062211_create_menu_list_table',0),(35,'2020_07_30_062211_create_recycle_rule_table',0),(36,'2020_07_30_062211_create_ring_group_table',0),(37,'2020_07_30_062211_create_roles_table',0),(38,'2020_07_30_062211_create_server_table',0),(39,'2020_07_30_062211_create_sms_table',0),(40,'2020_07_30_062211_create_sms_templete_table',0),(41,'2020_07_30_062211_create_smtp_setting_table',0),(42,'2020_07_30_062211_create_transfer_log_table',0),(43,'2020_07_30_062211_create_transfer_status_table',0),(44,'2020_07_30_062211_create_user_menu_table',0),(45,'2020_07_30_062211_create_user_setting_table',0),(46,'2020_07_30_062211_create_users_table',0);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

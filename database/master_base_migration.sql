-- MySQL dump 10.13  Distrib 8.0.20, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: master
-- ------------------------------------------------------
-- Server version	8.0.21
use master;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
TRUNCATE TABLE `migrations`;
INSERT INTO `migrations` VALUES (1,'2020_07_30_061251_create_asterisk_server_table',0),(2,'2020_07_30_061251_create_client_server_table',0),(3,'2020_07_30_061251_create_clients_table',0),(4,'2020_07_30_061251_create_conferencing_table',0),(5,'2020_07_30_061251_create_countries_table',0),(6,'2020_07_30_061251_create_dest_type_list_table',0),(7,'2020_07_30_061251_create_did_table',0),(8,'2020_07_30_061251_create_did_location_table',0),(9,'2020_07_30_061251_create_disposition_table',0),(10,'2020_07_30_061251_create_menu_list_table',0),(11,'2020_07_30_061251_create_mysql_connection_table',0),(12,'2020_07_30_061251_create_packages_table',0),(13,'2020_07_30_061251_create_paging_table',0),(14,'2020_07_30_061251_create_permissions_table',0),(15,'2020_07_30_061251_create_roles_table',0),(16,'2020_07_30_061251_create_server_table',0),(17,'2020_07_30_061251_create_sippeers_table',0),(18,'2020_07_30_061251_create_ssh_connection_table',0),(19,'2020_07_30_061251_create_states_table',0),(20,'2020_07_30_061251_create_timezone_table',0),(21,'2020_07_30_061251_create_user_asterisk_mapping_table',0),(22,'2020_07_30_061251_create_user_extensions_table',0),(23,'2020_07_30_061251_create_user_menu_table',0),(24,'2020_07_30_061251_create_user_package_table',0),(25,'2020_07_30_061251_create_user_payment_table',0),(26,'2020_07_30_061251_create_users_table',0),(27,'2020_07_30_061251_create_voip_did_table_table',0);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

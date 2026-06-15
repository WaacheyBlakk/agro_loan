-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 04, 2026 at 01:14 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `agro_loan`
--

-- --------------------------------------------------------

--
-- Table structure for table `agent_actions`
--

CREATE TABLE `agent_actions` (
  `id` int(11) NOT NULL,
  `agent_id` int(11) NOT NULL,
  `stage_id` int(11) NOT NULL,
  `action` enum('approved','rejected') NOT NULL,
  `notes` text DEFAULT NULL,
  `action_at` timestamp NOT NULL DEFAULT current_timestamp()
);

-- --------------------------------------------------------

--
-- Table structure for table `agent_profiles`
--

CREATE TABLE `agent_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `interest_rate` decimal(5,2) NOT NULL,
  `loan_terms` text DEFAULT NULL,
  `qualifications` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `id_card` varchar(255) DEFAULT NULL,
  `id_card_number` varchar(100) DEFAULT NULL,
  `passport_photo` varchar(255) DEFAULT NULL,
  `interior_photo` varchar(255) DEFAULT NULL,
  `exterior_photo` varchar(255) DEFAULT NULL,
  `tin_number` varchar(100) DEFAULT NULL,
  `certificate_photo` varchar(255) DEFAULT NULL,
  `gps_address` varchar(255) DEFAULT NULL
);

-- --------------------------------------------------------

--
-- Table structure for table `buyers`
--

CREATE TABLE `buyers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
);

-- --------------------------------------------------------

--
-- Table structure for table `buyer_profiles`
--

CREATE TABLE `buyer_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `gps_address` varchar(100) DEFAULT NULL,
  `digital_address` varchar(100) DEFAULT NULL,
  `alternate_phone` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
);

-- --------------------------------------------------------

--
-- Table structure for table `cart`
--

CREATE TABLE `cart` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
);

-- --------------------------------------------------------

--
-- Table structure for table `cart_items`
--

CREATE TABLE `cart_items` (
  `id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `produce_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp()
);

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` varchar(120) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
);

INSERT INTO `categories` (`id`, `name`, `slug`, `created_at`) VALUES
(1, 'Vegetables', 'vegetables', '2025-12-12 16:17:38'),
(2, 'Fruits', 'fruits', '2025-12-12 16:17:38'),
(3, 'Cereals & Grains', 'cereals-grains', '2025-12-12 16:17:38'),
(4, 'Roots & Tubers', 'roots-tubers', '2025-12-12 16:17:38'),
(5, 'Legumes', 'legumes', '2025-12-12 16:17:38'),
(6, 'Spices & Herbs', 'spices-herbs', '2025-12-12 16:17:38'),
(7, 'Livestock', 'livestock', '2025-12-12 16:17:38'),
(8, 'Poultry', 'poultry', '2025-12-12 16:17:38'),
(9, 'Dairy Products', 'dairy-products', '2025-12-12 16:17:38');

-- --------------------------------------------------------

--
-- Table structure for table `contact_messages`
--

CREATE TABLE `contact_messages` (
  `id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `subject` varchar(150) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
);

-- --------------------------------------------------------

--
-- Table structure for table `disbursements`
--

CREATE TABLE `disbursements` (
  `id` int(11) NOT NULL,
  `stage_id` int(11) NOT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `status` enum('approved','rejected') DEFAULT 'approved',
  `date_approved` datetime DEFAULT NULL
);

-- --------------------------------------------------------

--
-- Table structure for table `farmer_profiles`
--

CREATE TABLE `farmer_profiles` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `farm_type` enum('crop','livestock') NOT NULL,
  `crop_type` varchar(255) DEFAULT NULL,
  `crop_expected_duration_days` int(11) DEFAULT NULL,
  `livestock_type` varchar(255) DEFAULT NULL,
  `livestock_production_days` int(11) DEFAULT NULL,
  `acreage` decimal(8,2) DEFAULT NULL,
  `gps_coordinates` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `id_card` varchar(255) DEFAULT NULL,
  `id_card_number` varchar(100) DEFAULT NULL,
  `house_address` text DEFAULT NULL,
  `farmland_photos` text DEFAULT NULL,
  `passport_photo` varchar(255) DEFAULT NULL,
  `gps_address` varchar(255) DEFAULT NULL
);

-- --------------------------------------------------------

--
-- Table structure for table `loan_applications`
--

CREATE TABLE `loan_applications` (
  `id` int(11) NOT NULL,
  `farmer_id` int(11) NOT NULL,
  `agent_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `purpose` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','completed') DEFAULT 'pending',
  `current_stage` int(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `disbursed_amount` decimal(10,2) DEFAULT 0.00,
  `outstanding_balance` decimal(12,2) DEFAULT NULL COMMENT 'Remaining repayable amount (principal + interest). NULL = not yet calculated'
);

-- --------------------------------------------------------

--
-- Table structure for table `loan_repayments`
--

CREATE TABLE `loan_repayments` (
  `id` int(11) NOT NULL,
  `loan_id` int(11) NOT NULL,
  `farmer_id` int(11) NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL,
  `balance_before` decimal(12,2) NOT NULL,
  `balance_after` decimal(12,2) NOT NULL,
  `repayment_type` enum('partial','full') NOT NULL,
  `proof_filename` varchar(255) DEFAULT NULL,
  `proof_file_type` enum('image','document') DEFAULT 'image',
  `status` enum('pending','confirmed','rejected') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `agent_note` text DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL
);

-- --------------------------------------------------------

--
-- Table structure for table `loan_stages`
--

CREATE TABLE `loan_stages` (
  `id` int(11) NOT NULL,
  `application_id` int(11) NOT NULL,
  `stage_number` tinyint(4) NOT NULL,
  `required_amount` decimal(12,2) NOT NULL,
  `disbursed_amount` decimal(12,2) DEFAULT 0.00,
  `status` enum('pending','verified','rejected','awaiting_disbursement','disbursed','disbursement_rejected') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `disbursed` tinyint(1) DEFAULT 0
);

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `buyer_id` int(11) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL,
  `status` varchar(40) NOT NULL DEFAULT 'pending',
  `shipping_address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
);

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `produce_id` int(11) NOT NULL,
  `farmer_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL
);

-- --------------------------------------------------------

--
-- Table structure for table `produce_listings`
--

CREATE TABLE `produce_listings` (
  `id` int(11) NOT NULL,
  `farmer_id` int(11) NOT NULL,
  `produce_name` varchar(255) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `price_per_bag` decimal(10,2) NOT NULL,
  `bags_available` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `farmer_id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `price` decimal(10,2) DEFAULT NULL,
  `bags` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
);

-- --------------------------------------------------------

--
-- Table structure for table `product_images`
--

CREATE TABLE `product_images` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL
);

-- --------------------------------------------------------

--
-- Table structure for table `stage_proofs`
--

CREATE TABLE `stage_proofs` (
  `id` int(11) NOT NULL,
  `stage_id` int(11) NOT NULL,
  `farmer_id` int(11) DEFAULT NULL,
  `filename` varchar(255) NOT NULL,
  `file_type` enum('image','video','other') DEFAULT 'image',
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','verified','rejected') DEFAULT 'pending',
  `proof_type` varchar(20) DEFAULT 'after'
);

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('farmer','agent','admin') NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','verified','rejected') NOT NULL DEFAULT 'pending',
  `reset_token_hash` varchar(64) DEFAULT NULL,
  `reset_token_expires_at` datetime DEFAULT NULL
);

--
-- Table structure for table `wishlist_items`
--

CREATE TABLE `wishlist_items` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
);

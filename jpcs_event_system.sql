-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 05, 2026 at 12:28 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `jpcs_event_system`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `participant_id` varchar(50) NOT NULL,
  `scanned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `event_id`, `participant_id`, `scanned_at`) VALUES
(13, 13, 'EVT20260803-30', '2026-08-03 14:38:31'),
(14, 13, 'EVT20260803-32', '2026-08-03 14:40:39'),
(15, 13, 'EVT20260803-34', '2026-08-03 14:55:38'),
(16, 15, 'EVT20260905-40', '2026-09-05 02:44:00');

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `id` int(11) NOT NULL,
  `title` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `location` varchar(255) DEFAULT NULL,
  `event_time` time DEFAULT NULL,
  `poster` varchar(255) DEFAULT NULL,
  `pass_image` varchar(255) DEFAULT NULL,
  `status` enum('upcoming','done') DEFAULT 'upcoming'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`id`, `title`, `description`, `event_date`, `created_at`, `location`, `event_time`, `poster`, `pass_image`, `status`) VALUES
(13, 'CCS NIGHT', 'adgasdgasd', '2026-08-14', '2026-08-03 14:35:32', 'CMU GROUND', '22:37:00', 'uploads/events/1785767732_558932599_784835094434656_2893453200124666014_n.jpg', 'uploads/events/1785767732_598eaef728f9f3b113af8ce1574d76b3.jpg', 'done'),
(14, 'gadgads', 'dasfadsf', '2026-08-19', '2026-08-03 14:57:45', 'CMU GROUND', '13:57:00', 'uploads/events/1785769065_68429a11f7430abed2e2149579d77e61.jpg', 'uploads/events/1785769065_598eaef728f9f3b113af8ce1574d76b3.jpg', 'upcoming'),
(15, 'sam', 'dsagdas', '2026-08-20', '2026-08-03 16:38:55', 'TARLAC ', '12:40:00', 'uploads/events/1785775135_68429a11f7430abed2e2149579d77e61.jpg', 'uploads/events/1785775135_dafcd8bb37cf045ceb7873e6cd9c4dd5.jpg', 'done');

-- --------------------------------------------------------

--
-- Table structure for table `highlights`
--

CREATE TABLE `highlights` (
  `id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `poster` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `highlight_images`
--

CREATE TABLE `highlight_images` (
  `id` int(11) NOT NULL,
  `event_id` int(11) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `highlight_images`
--

INSERT INTO `highlight_images` (`id`, `event_id`, `image_path`, `uploaded_at`) VALUES
(67, 13, 'uploads/highlights/gallery/1785769373_WIN_20260410_16_23_20_Pro.jpg', '2026-08-03 15:02:53'),
(87, 13, 'uploads/highlights/gallery/1785778473_c6525b51-4ef1-4eef-8f75-d38f80511c51.jpg', '2026-08-03 17:34:33'),
(90, 13, 'uploads/highlights/gallery/1785779250_585501420_818419587742873_3910280971315306292_n.jpg', '2026-08-03 17:47:30'),
(91, 13, 'uploads/highlights/gallery/1785779250_pitik ni eys.jpg', '2026-08-03 17:47:30');

-- --------------------------------------------------------

--
-- Table structure for table `participants`
--

CREATE TABLE `participants` (
  `id` int(11) NOT NULL,
  `student_id` varchar(50) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `year_section` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `qr_code` varchar(255) DEFAULT NULL,
  `status` enum('pending','confirmed','rejected') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `registrations`
--

CREATE TABLE `registrations` (
  `id` int(11) NOT NULL,
  `event_id` int(11) DEFAULT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `student_id` varchar(50) DEFAULT NULL,
  `course_year_section` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `contact` varchar(20) DEFAULT NULL,
  `registered_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `confirmed_by` varchar(255) DEFAULT NULL,
  `status` enum('pending','confirmed') DEFAULT 'pending',
  `control_number` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `registrations`
--

INSERT INTO `registrations` (`id`, `event_id`, `student_name`, `student_id`, `course_year_section`, `email`, `contact`, `registered_at`, `confirmed_by`, `status`, `control_number`) VALUES
(30, 13, 'ace buensuceso', '202201775621', 'BSIT 1st Year - D', 'pitiknieys3@gmail.com', '09198843664', '2026-08-03 14:36:07', 'Samantha R. Celeste', 'confirmed', 'EVT20260803-30'),
(32, 13, 'eiseu oliveros', '20220177562', 'BSIT 1st Year - D', 'aceoliveros00@gmail.com', '09198843664', '2026-08-03 14:37:18', 'Samantha R. Celeste', 'confirmed', 'EVT20260803-32'),
(33, 13, 'Billie  Mapoy', '2002848123', 'BSIT 4th Year - B', 'bjmapoy@gmail.com', '09198843664', '2026-08-03 14:52:57', 'Samantha R. Celeste', 'confirmed', 'EVT20260803-33'),
(34, 13, 'ace oliveros', '2018589625', 'BSIT 4th Year - B', 'aceoliveros00@gmail.com', '09198843664', '2026-08-03 14:53:48', 'Samantha R. Celeste', 'confirmed', 'EVT20260803-34'),
(35, 14, 'billy', '52058848', 'BSIT 1st Year - C', 'bjmapoy@gmail.com', '09198843664', '2026-08-03 14:58:28', 'Samantha R. Celeste', 'confirmed', 'EVT20260803-35'),
(36, 14, 'sam ', '20220177534', 'BSIT 1st Year - D', 'Samanthayvette041@gmail.com', '09198843664', '2026-08-03 16:37:18', 'Samantha R. Celeste', 'confirmed', 'EVT20260803-36'),
(37, 15, 'saaaaaan', '213325432521', 'BSIT 1st Year - D', 'Samanthayvette041@gmail.com', '09198843664', '2026-08-03 16:39:11', 'Samantha R. Celeste', 'confirmed', 'EVT20260803-37'),
(38, 14, 'gena', '3425432543', 'BSIT 4th Year - A', 'genalynselentes@gmai.com', '09198843664', '2026-09-05 02:41:31', 'Samantha R. Celeste', 'confirmed', 'EVT20260905-38'),
(39, 15, 'ace', '34214325', 'BSIT 1st Year - A', 'aceoliveros00@gmail.com', '09198843664', '2026-09-05 02:43:03', NULL, 'pending', NULL),
(40, 15, 'ace', '34214325', 'BSIT 1st Year - A', 'aceoliveros00@gmail.com', '09198843664', '2026-09-05 02:43:04', 'Samantha R. Celeste', 'confirmed', 'EVT20260905-40');

-- --------------------------------------------------------

CREATE TABLE `student_accounts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `course_year` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_accounts_student_id_unique` (`student_id`),
  UNIQUE KEY `student_accounts_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin_advisor','officer') NOT NULL,
  `position` varchar(100) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `name`, `password`, `role`, `position`, `photo`, `created_at`) VALUES
(2, 'advisor', 'Ace O. Buensuceso', '12345', 'admin_advisor', 'Advisor', 'uploads/bafc59c0-9c66-4bbb-9718-e9bab05879bf.jpg', '2026-08-01 13:37:47'),
(3, 'sam', 'Samantha R. Celeste', '12345', 'officer', 'President', 'uploads/c6525b51-4ef1-4eef-8f75-d38f80511c51.jpg', '2026-08-01 14:21:21');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`event_id`,`participant_id`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `highlights`
--
ALTER TABLE `highlights`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `highlight_images`
--
ALTER TABLE `highlight_images`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `participants`
--
ALTER TABLE `participants`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_id` (`student_id`);

--
-- Indexes for table `registrations`
--
ALTER TABLE `registrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `highlights`
--
ALTER TABLE `highlights`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `highlight_images`
--
ALTER TABLE `highlight_images`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=92;

--
-- AUTO_INCREMENT for table `participants`
--
ALTER TABLE `participants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `registrations`
--
ALTER TABLE `registrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

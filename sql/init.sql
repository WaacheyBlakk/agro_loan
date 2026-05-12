CREATE DATABASE IF NOT EXISTS agro_loan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE agro_loan;

-- users: both farmers and agents
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('farmer','agent') NOT NULL,
  name VARCHAR(255) NOT NULL,
  phone VARCHAR(50),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- agent profiles (one-to-one with users where role='agent')
CREATE TABLE agent_profiles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  interest_rate DECIMAL(5,2) NOT NULL,
  loan_terms TEXT,
  qualifications TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- farmer profiles (one-to-one with users where role='farmer')
CREATE TABLE farmer_profiles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  farm_type ENUM('crop','livestock') NOT NULL,
  crop_type VARCHAR(255),         -- nullable if livestock
  crop_expected_duration_days INT,
  livestock_type VARCHAR(255),    -- nullable if crop
  livestock_production_days INT,
  acreage DECIMAL(8,2),
  gps_coordinates VARCHAR(255),   -- optional
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- loan applications
CREATE TABLE loan_applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  farmer_id INT NOT NULL,
  agent_id INT NOT NULL,
  title VARCHAR(255),
  amount DECIMAL(12,2) NOT NULL,
  purpose TEXT,
  status ENUM('pending','approved','rejected','completed') DEFAULT 'pending',
  current_stage TINYINT DEFAULT 1,  -- 1,2,3
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (farmer_id) REFERENCES users(id),
  FOREIGN KEY (agent_id) REFERENCES users(id)
);

-- stage disbursements + state
CREATE TABLE loan_stages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  application_id INT NOT NULL,
  stage_number TINYINT NOT NULL, -- 1,2,3
  required_amount DECIMAL(12,2) NOT NULL,
  disbursed_amount DECIMAL(12,2) DEFAULT 0,
  status ENUM('pending','awaiting_proof','under_review','approved','rejected','completed') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (application_id) REFERENCES loan_applications(id) ON DELETE CASCADE
);

-- uploaded proof files for stages
CREATE TABLE stage_proofs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stage_id INT NOT NULL,
  farmer_id INT,
  filename VARCHAR(255) NOT NULL,
  file_type ENUM('image','video','other') DEFAULT 'image',
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (stage_id) REFERENCES loan_stages(id) ON DELETE CASCADE,
  FOREIGN KEY (farmer_id) REFERENCES users(id) ON DELETE SET NULL
);


-- agent audit log (approvals/rejections)
CREATE TABLE agent_actions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  agent_id INT NOT NULL,
  stage_id INT NOT NULL,
  action ENUM('approved','rejected') NOT NULL,
  notes TEXT,
  action_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (agent_id) REFERENCES users(id),
  FOREIGN KEY (stage_id) REFERENCES loan_stages(id)
);

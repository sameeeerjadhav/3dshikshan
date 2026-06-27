CREATE DATABASE IF NOT EXISTS `3dshikshan` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `3dshikshan`;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    login_id VARCHAR(120) NOT NULL UNIQUE,
    mobile_no VARCHAR(20) DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'coordinator', 'student') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE users
    MODIFY COLUMN role ENUM('admin', 'coordinator', 'student') NOT NULL;


CREATE TABLE IF NOT EXISTS colleges (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(200) NOT NULL UNIQUE,
    country    VARCHAR(100) NOT NULL,
    state      VARCHAR(100) NOT NULL,
    district   VARCHAR(100) NOT NULL,
    address    VARCHAR(255) NOT NULL DEFAULT '',
    latitude   VARCHAR(50) NOT NULL DEFAULT '',
    longitude  VARCHAR(50) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS courses (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_name      VARCHAR(200) NOT NULL UNIQUE,
    description      VARCHAR(1000) NOT NULL,
    duration         VARCHAR(100) NOT NULL,
    fees             VARCHAR(100) NOT NULL,
    required_details VARCHAR(1000) NOT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS student_profiles (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    first_name   VARCHAR(80) NOT NULL,
    middle_name  VARCHAR(80) DEFAULT '',
    last_name    VARCHAR(80) NOT NULL,
    mobile_no    VARCHAR(20) NOT NULL,
    email        VARCHAR(120) NOT NULL UNIQUE,
    state        VARCHAR(100) NOT NULL,
    district     VARCHAR(100) NOT NULL,
    college_id   INT UNSIGNED NOT NULL,
    course_id    INT UNSIGNED NOT NULL,
    academic_year VARCHAR(10) NOT NULL DEFAULT 'Unknown',
    semester     VARCHAR(10) NOT NULL DEFAULT 'Unknown',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_student_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_student_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE RESTRICT,
    CONSTRAINT fk_student_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS registration_payments (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_profile_id  INT UNSIGNED NOT NULL,
    razorpay_order_id   VARCHAR(80) NOT NULL,
    razorpay_payment_id VARCHAR(80) NOT NULL,
    amount_rupees       DECIMAL(10,2) NOT NULL,
    currency            VARCHAR(10) NOT NULL DEFAULT 'INR',
    status              VARCHAR(30) NOT NULL DEFAULT 'captured',
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payment_student FOREIGN KEY (student_profile_id) REFERENCES student_profiles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS coordinators (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL UNIQUE,
    first_name    VARCHAR(80) NOT NULL,
    second_name   VARCHAR(80) DEFAULT '',
    last_name     VARCHAR(80) NOT NULL,
    email         VARCHAR(120) NOT NULL UNIQUE,
    mobile_no     VARCHAR(20) NOT NULL,
    address_line1 VARCHAR(200) NOT NULL,
    address_line2 VARCHAR(200) DEFAULT '',
    state         VARCHAR(100) NOT NULL,
    district      VARCHAR(100) NOT NULL,
    pin           VARCHAR(12) NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_coordinator_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS coordinator_colleges (
    coordinator_id INT UNSIGNED NOT NULL,
    college_id     INT UNSIGNED NOT NULL,
    PRIMARY KEY (coordinator_id, college_id),
    CONSTRAINT fk_cc_coordinator FOREIGN KEY (coordinator_id) REFERENCES coordinators(id) ON DELETE CASCADE,
    CONSTRAINT fk_cc_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS coordinator_sessions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coordinator_id  INT UNSIGNED NOT NULL,
    college_id      INT UNSIGNED NOT NULL,
    session_date    DATE NOT NULL,
    session_details VARCHAR(2000) NOT NULL,
    session_type    VARCHAR(50) NOT NULL DEFAULT 'Class',
    notes           VARCHAR(2000) NOT NULL DEFAULT '',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_cs_coordinator FOREIGN KEY (coordinator_id) REFERENCES coordinators(id) ON DELETE CASCADE,
    CONSTRAINT fk_cs_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE,
    INDEX idx_cs_college_date (college_id, session_date),
    INDEX idx_cs_coordinator_date (coordinator_id, session_date)
);

CREATE TABLE IF NOT EXISTS student_notifications (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                INT UNSIGNED NOT NULL,
    student_profile_id     INT UNSIGNED NOT NULL,
    college_id             INT UNSIGNED NOT NULL,
    coordinator_session_id INT UNSIGNED NULL,
    title                  VARCHAR(255) NOT NULL,
    message                VARCHAR(1200) NOT NULL,
    is_read                TINYINT(1) NOT NULL DEFAULT 0,
    created_at             TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_sn_student FOREIGN KEY (student_profile_id) REFERENCES student_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_sn_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE,
    CONSTRAINT fk_sn_session FOREIGN KEY (coordinator_session_id) REFERENCES coordinator_sessions(id) ON DELETE SET NULL,
    INDEX idx_sn_student_created (student_profile_id, created_at),
    INDEX idx_sn_user_unread (user_id, is_read)
);

CREATE TABLE IF NOT EXISTS session_attendance (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id         INT UNSIGNED NOT NULL,
    student_profile_id INT UNSIGNED NOT NULL,
    status             ENUM('present','absent') NOT NULL DEFAULT 'absent',
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sa_session FOREIGN KEY (session_id) REFERENCES coordinator_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_sa_student FOREIGN KEY (student_profile_id) REFERENCES student_profiles(id) ON DELETE CASCADE,
    UNIQUE KEY uk_sa_session_student (session_id, student_profile_id),
    INDEX idx_sa_session (session_id),
    INDEX idx_sa_student (student_profile_id)
);

CREATE TABLE IF NOT EXISTS coordinator_tickets (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coordinator_id          INT UNSIGNED NOT NULL,
    student_profile_id      INT UNSIGNED NOT NULL,
    college_id              INT UNSIGNED NOT NULL,
    subject                 VARCHAR(180) NOT NULL,
    message                 VARCHAR(2000) NOT NULL,
    status                  ENUM('open','in_progress','resolved') NOT NULL DEFAULT 'open',
    is_seen_by_coordinator  TINYINT(1) NOT NULL DEFAULT 0,
    created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ct_coordinator FOREIGN KEY (coordinator_id) REFERENCES coordinators(id) ON DELETE CASCADE,
    CONSTRAINT fk_ct_student FOREIGN KEY (student_profile_id) REFERENCES student_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_ct_college FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE,
    INDEX idx_ct_coordinator_seen (coordinator_id, is_seen_by_coordinator),
    INDEX idx_ct_student_created (student_profile_id, created_at),
    INDEX idx_ct_status_created (status, created_at)
);


CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(120) NOT NULL,
    token VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX (email)
);


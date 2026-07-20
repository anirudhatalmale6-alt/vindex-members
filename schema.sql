-- Vindex members platform — database schema (MySQL 5.7+ / 8.x, works on one.com)
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('admin','leadership','employee') NOT NULL DEFAULT 'employee',
  lang          ENUM('en','da') NOT NULL DEFAULT 'en',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login    TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Top-level sidebar entries (the left-hand menu going down the page)
CREATE TABLE IF NOT EXISTS sections (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  title_en   VARCHAR(200) NOT NULL,
  title_da   VARCHAR(200) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  visibility ENUM('all','leadership') NOT NULL DEFAULT 'all',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Content blocks inside a section. Self-referencing tree via parent_id (infinite depth).
--   type='text'    -> a paragraph/rich-text block (body shown directly)
--   type='foldout' -> a heading with an arrow that expands to reveal body + child blocks
CREATE TABLE IF NOT EXISTS blocks (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  section_id INT NOT NULL,
  parent_id  INT NULL,
  type       ENUM('text','foldout') NOT NULL DEFAULT 'text',
  title_en   VARCHAR(300) NOT NULL DEFAULT '',
  title_da   VARCHAR(300) NOT NULL DEFAULT '',
  body_en    MEDIUMTEXT NULL,
  body_da    MEDIUMTEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  visibility ENUM('all','leadership') NOT NULL DEFAULT 'all',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_section (section_id),
  INDEX idx_parent (parent_id),
  CONSTRAINT fk_block_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
  CONSTRAINT fk_block_parent  FOREIGN KEY (parent_id)  REFERENCES blocks(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

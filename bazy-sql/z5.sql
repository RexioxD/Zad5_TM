-- Zadanie 5: osobne konta i historia wejść w istniejącej bazie rexio_z4.
CREATE TABLE IF NOT EXISTS z5_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(128) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    avatar VARCHAR(200) DEFAULT NULL,
    worker TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

CREATE TABLE IF NOT EXISTS z5_goscieportalu (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ipaddress VARCHAR(45) NOT NULL,
    datetime DATETIME NOT NULL,
    browser TEXT,
    screen_size VARCHAR(30),
    window_size VARCHAR(30),
    colors SMALLINT,
    cookies TINYINT,
    java TINYINT,
    language VARCHAR(50),
    login_username VARCHAR(128),
    login_success TINYINT(1),
    INDEX(ipaddress)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_polish_ci;

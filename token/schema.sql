CREATE TABLE tokens (
    token_id       CHAR(64)             NOT NULL PRIMARY KEY,
    token_hash     VARCHAR(255)         NOT NULL,
    auth_me        VARCHAR(255)         NOT NULL,
    auth_client_id VARCHAR(255)         NOT NULL,
    auth_scope     VARCHAR(255)         NOT NULL,
    created        TIMESTAMP    DEFAULT NOW() NOT NULL,
    last_use       TIMESTAMP    NULL,
    revoked        TIMESTAMP    NULL
);
CREATE TABLE settings (
    setting_name   VARCHAR(255) NOT NULL,
    setting_value  VARCHAR(255) NOT NULL,
    CONSTRAINT setting UNIQUE (setting_name, setting_value)
);

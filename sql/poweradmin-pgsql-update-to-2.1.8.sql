BEGIN;
-- Logging
CREATE TABLE log_domains_type (
    id SERIAL PRIMARY KEY,
    name varchar(32) NOT NULL UNIQUE
);

CREATE TABLE log_records_type (
    id SERIAL PRIMARY KEY,
    name varchar(32) NOT NULL UNIQUE
);

CREATE TABLE log_records_data (
    id SERIAL PRIMARY KEY,
    domain_id INTEGER,
    name varchar(255),
    type varchar(6),
    content text,
    ttl integer,
    prio integer,
    change_date integer
);

CREATE TABLE log_domains (
    id SERIAL PRIMARY KEY,
    log_domains_type_id INTEGER REFERENCES log_domains_type(id) NOT NULL,
    domain_name varchar(255) NOT NULL,
    timestamp timestamp,
    username varchar(64) NOT NULL,
    user_approve varchar(64)
);

CREATE INDEX fk_log_domains_1_idx ON log_domains(log_domains_type_id);

CREATE TABLE log_records (
    id SERIAL PRIMARY KEY,
    log_records_type_id INTEGER REFERENCES log_records_type(id) NOT NULL,
    timestamp timestamp NOT NULL,
    username varchar(64) NOT NULL,
    user_approve varchar(64),
    prior integer REFERENCES log_records_data(id),
    after integer REFERENCES log_records_data(id)
);

INSERT INTO log_domains_type VALUES (1, 'domain_create');
INSERT INTO log_domains_type VALUES (2, 'domain_delete');

INSERT INTO log_records_type VALUES (1, 'record_create');
INSERT INTO log_records_type VALUES (2, 'record_edit');
INSERT INTO log_records_type VALUES (3, 'record_delete');

COMMIT;

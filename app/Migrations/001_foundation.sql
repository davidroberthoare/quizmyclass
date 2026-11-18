CREATE TABLE qmc_application
(
    id INTEGER PRIMARY KEY CHECK (id = 1),
    schema_name TEXT NOT NULL,
    installed_at TEXT NOT NULL
);

INSERT INTO qmc_application (id, schema_name, installed_at)
VALUES (1, 'quizmyclass', strftime('%Y-%m-%dT%H:%M:%SZ', 'now'));


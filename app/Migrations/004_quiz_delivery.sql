-- Quiz settings are edited while a quiz is in `editing` and copied into an
-- immutable revision on activation.
ALTER TABLE rooms ADD COLUMN settings_json TEXT NOT NULL DEFAULT '{}';

CREATE TABLE quiz_revisions
(
    id INTEGER PRIMARY KEY,
    room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    revision_number INTEGER NOT NULL CHECK (revision_number > 0),
    settings_json TEXT NOT NULL,
    questions_json TEXT NOT NULL,
    created_by INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at TEXT NOT NULL,
    UNIQUE (room_id, revision_number)
);

CREATE TABLE quiz_attempts
(
    id INTEGER PRIMARY KEY,
    revision_id INTEGER NOT NULL REFERENCES quiz_revisions(id) ON DELETE CASCADE,
    room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    attempt_number INTEGER NOT NULL CHECK (attempt_number > 0),
    seed INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'in_progress' CHECK (status IN ('in_progress', 'submitted', 'expired')),
    started_at TEXT NOT NULL,
    deadline_at TEXT,
    submitted_at TEXT,
    score REAL,
    max_score REAL,
    UNIQUE (revision_id, user_id, attempt_number)
);

CREATE INDEX quiz_attempts_room_user_idx ON quiz_attempts(room_id, user_id);
CREATE INDEX quiz_attempts_revision_idx ON quiz_attempts(revision_id);

CREATE TABLE quiz_answers
(
    attempt_id INTEGER NOT NULL REFERENCES quiz_attempts(id) ON DELETE CASCADE,
    question_id INTEGER NOT NULL,
    answer_json TEXT NOT NULL,
    is_correct INTEGER CHECK (is_correct IN (0, 1)),
    points_awarded REAL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (attempt_id, question_id)
);

ALTER TABLE rooms ADD COLUMN max_questions INTEGER NOT NULL DEFAULT 100;
ALTER TABLE rooms ADD COLUMN student_question_cap INTEGER NOT NULL DEFAULT 10;

CREATE TABLE questions
(
    id INTEGER PRIMARY KEY,
    room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    author_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    type TEXT NOT NULL CHECK (
        type IN ('multiple_choice', 'multiple_select', 'true_false', 'short_text', 'numeric')
    ),
    prompt_html TEXT NOT NULL CHECK (length(prompt_html) BETWEEN 1 AND 20000),
    explanation_html TEXT NOT NULL DEFAULT '',
    choices_json TEXT NOT NULL DEFAULT '[]',
    answer_json TEXT NOT NULL,
    points REAL NOT NULL DEFAULT 1 CHECK (points BETWEEN 0.1 AND 100),
    status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'approved', 'rejected', 'hidden')),
    position INTEGER NOT NULL CHECK (position > 0),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX questions_room_position_idx ON questions(room_id, position);
CREATE INDEX questions_room_author_idx ON questions(room_id, author_id);
CREATE INDEX questions_status_idx ON questions(room_id, status);

CREATE TABLE users
(
    id INTEGER PRIMARY KEY,
    google_subject TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL,
    display_name TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    last_login_at TEXT NOT NULL
);

CREATE TABLE rooms
(
    id INTEGER PRIMARY KEY,
    slug TEXT NOT NULL UNIQUE CHECK (slug = lower(slug) AND length(slug) BETWEEN 3 AND 60),
    name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
    owner_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    state TEXT NOT NULL CHECK (state IN ('editing', 'active', 'closed')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX rooms_owner_id_idx ON rooms(owner_id);

CREATE TABLE room_memberships
(
    room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK (role IN ('owner', 'student')),
    created_at TEXT NOT NULL,
    PRIMARY KEY (room_id, user_id)
);

CREATE INDEX room_memberships_user_id_idx ON room_memberships(user_id);

CREATE TABLE audit_events
(
    id INTEGER PRIMARY KEY,
    actor_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    room_id INTEGER REFERENCES rooms(id) ON DELETE CASCADE,
    action TEXT NOT NULL,
    created_at TEXT NOT NULL,
    details_json TEXT NOT NULL
);

CREATE INDEX audit_events_room_id_idx ON audit_events(room_id);

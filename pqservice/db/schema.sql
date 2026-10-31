PRAGMA foreign_keys = ON;

-- USERS
CREATE TABLE IF NOT EXISTS users (
  uuid TEXT PRIMARY KEY,
  email TEXT,
  login TEXT UNIQUE,
  password_hash TEXT,

  localstatus TEXT CHECK(localstatus IN ('active','expired','disabled','pending')),
  localexpire INTEGER,

  vip INTEGER DEFAULT 0,
  coins INTEGER DEFAULT 0,
  price_per_day INTEGER DEFAULT 1,

  device TEXT,
  contact TEXT,

  updated_at INTEGER
);

-- TRANSACTIONS
CREATE TABLE IF NOT EXISTS transactions (
  id TEXT PRIMARY KEY,
  user_uuid TEXT,
  days INTEGER,
  coins_spent INTEGER DEFAULT 0,
  status TEXT CHECK(status IN ('pending','success','failed')),
  created_at INTEGER,
  FOREIGN KEY(user_uuid) REFERENCES users(uuid)
);

-- IDEMPOTENCY
CREATE TABLE IF NOT EXISTS idempotency_keys (
  key TEXT PRIMARY KEY,
  user_uuid TEXT,
  response TEXT,
  created_at INTEGER
);

-- DESYNC ISSUES
CREATE TABLE IF NOT EXISTS desync_issues (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_uuid TEXT,
  type TEXT,
  details TEXT,
  detected_at INTEGER
);

-- INDEXES
CREATE INDEX IF NOT EXISTS idx_users_login ON users(login);
CREATE INDEX IF NOT EXISTS idx_transactions_user ON transactions(user_uuid);
CREATE INDEX IF NOT EXISTS idx_desync_user ON desync_issues(user_uuid);
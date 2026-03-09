-- P29-LOW-AUTH-001: Index on membre.session_token to speed up session validation queries.
-- basicprivatephp.php validates sessions via WHERE login = ? AND session_token = ?,
-- and the logout path nullifies session_token WHERE login = ?. Login is the PK but
-- session_token has no index, causing a full row scan for each token lookup.
ALTER TABLE membre ADD INDEX IF NOT EXISTS idx_membre_session_token (session_token(64));

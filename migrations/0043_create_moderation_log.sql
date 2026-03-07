-- MED-034: Moderation audit trail for moderator edits on forum posts
CREATE TABLE IF NOT EXISTS moderation_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  moderator_login VARCHAR(255) NOT NULL,
  target_post_id INT NOT NULL,
  post_type ENUM('sujet','reponse') NOT NULL DEFAULT 'reponse',
  original_content TEXT NOT NULL,
  new_content TEXT NOT NULL,
  action_at INT UNSIGNED NOT NULL,
  INDEX idx_moderator (moderator_login)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

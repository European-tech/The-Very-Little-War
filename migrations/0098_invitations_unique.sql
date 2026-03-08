-- Migration 0098: Add UNIQUE constraint on invitations(invite, idalliance) to prevent duplicate invites
ALTER TABLE invitations ADD UNIQUE KEY IF NOT EXISTS uk_invite_alliance (invite, idalliance);

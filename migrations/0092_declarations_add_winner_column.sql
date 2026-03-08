-- ALLIANCE-MED-003: Add winner column to declarations if not present.
-- 0=draw, 1=alliance1 wins, 2=alliance2 wins.
ALTER TABLE declarations
    ADD COLUMN IF NOT EXISTS winner TINYINT NOT NULL DEFAULT 0
        COMMENT '0=draw,1=alliance1,2=alliance2';

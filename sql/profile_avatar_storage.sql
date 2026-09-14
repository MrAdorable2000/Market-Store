-- Profile avatar storage fallback for installations where Apache cannot write to assets/uploads.
ALTER TABLE users ADD COLUMN avatar_blob MEDIUMBLOB NULL;
ALTER TABLE users ADD COLUMN avatar_mime VARCHAR(50) NULL;

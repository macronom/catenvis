-- Serves the import queue round robin per user: the worker picks the next
-- pending entry by (user_id, id) after the user it served last.
ALTER TABLE import_queue ADD KEY idx_import_pending (status, user_id, id);

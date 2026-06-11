-- Demo data. Idempotent (INSERT IGNORE keyed on unique columns).
-- Demo login: demo@reliableform.dev / demo1234

INSERT IGNORE INTO users (email, name, password_hash)
VALUES (
    'demo@reliableform.dev',
    'Demo Owner',
    '$2y$12$dwi4Gjsmcgpw6DOA1PSYsOJFW8wGBWsngrsmWmq5gisjbiCTHafum'
);

-- Deterministic demo API key (obviously fake hex) so docs and tests can use it.
UPDATE users
SET api_key = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef'
WHERE email = 'demo@reliableform.dev';

INSERT IGNORE INTO forms (user_id, public_id, title, description, fields, is_published)
SELECT
    u.id,
    'demofeedbk',
    'Customer Feedback',
    'Tell us how we did. Takes about a minute — every answer helps us improve.',
    '[
      {"id":"f_name01","type":"text","label":"Full name","required":true,"placeholder":"Jane Doe"},
      {"id":"f_email1","type":"email","label":"Email address","required":true,"placeholder":"jane@example.com"},
      {"id":"f_dept01","type":"select","label":"Which team did you talk to?","required":true,"options":["Sales","Support","Billing","Other"]},
      {"id":"f_prod01","type":"checkbox","label":"Which products do you use?","required":false,"options":["Forms","PDF Reports","Email Alerts","API"]},
      {"id":"f_first1","type":"radio","label":"Was this your first contact with us?","required":true,"options":["Yes","No"]},
      {"id":"f_visit1","type":"date","label":"When did you contact us?","required":false},
      {"id":"f_count1","type":"number","label":"How many times have you contacted support this year?","required":false,"placeholder":"0"},
      {"id":"f_rate01","type":"rating","label":"How would you rate the experience?","required":true,"max":5},
      {"id":"f_notes1","type":"textarea","label":"Anything else we should know?","required":false,"placeholder":"The good, the bad, the slow..."}
    ]',
    1
FROM users u
WHERE u.email = 'demo@reliableform.dev';

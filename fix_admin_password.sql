UPDATE users SET password_hash='$2y$10$7vmWMDaG28Ngu3C8ZpcXuOUeXmcrHvSoj9///FOm39GV2IO9p1qDq' WHERE email='admin@55ak.xyz';
SELECT id, email, password_hash FROM users WHERE email='admin@55ak.xyz';

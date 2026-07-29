# Laravel CloudWatch

- The `cloudwatch` log channel buffers records into a cache store; the scheduled `cloudwatch:ship` command ships them. No AWS calls in the request path.
- Needs `CLOUDWATCH_ENABLED=true` and a running scheduler.
- The buffer is capped and delivery is at least once.
- Configure via `config/cloudwatch.php`, never edit the package.
- IMPORTANT: activate the `laravel-cloudwatch` skill for any Laravel CloudWatch work.

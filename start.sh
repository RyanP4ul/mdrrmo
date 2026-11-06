
#!/bin/sh

set -e

echo "Starting Laravel application..."

php artisan serve &
LARAVEL_PID=$!

echo "Starting Laravel Reverb..."

php artisan reverb:start --host=0.0.0.0 --port=6969 &
REVERB_PID=$!

echo "Starting Laravel queue worker..."

php artisan queue:work --sleep=1 --tries=3 --timeout=30 &
QUEUE_PID=$!

# Stop both processes if either one exits
cleanup() {
    echo "Stopping Laravel, Reverb, and queue worker..."
    kill "$LARAVEL_PID" "$REVERB_PID" "$QUEUE_PID" 2>/dev/null || true
    wait
}

trap cleanup INT TERM

wait -n "$LARAVEL_PID" "$REVERB_PID" "$QUEUE_PID"

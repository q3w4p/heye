import sys
import subprocess
import os
import signal
import time

def start_bot(token, user_id):
    """Start a bot instance with the given token"""
    log_file = f"/tmp/bot_{user_id}.log"
    pid_file = f"/tmp/bot_{user_id}.pid"

    # Get the directory where this script is located
    bot_dir = os.path.dirname(os.path.abspath(__file__))

    # Check if bot is already running
    if os.path.exists(pid_file):
        with open(pid_file, 'r') as f:
            old_pid = int(f.read().strip())
        try:
            os.kill(old_pid, 0)  # Check if process exists
            print(f"Bot already running with PID {old_pid}")
            return old_pid
        except OSError:
            # Process doesn't exist, remove stale PID file
            os.remove(pid_file)

    # Create/clear log file
    with open(log_file, 'w') as f:
        f.write(f"=== Bot started at {time.strftime('%Y-%m-%d %H:%M:%S')} ===\n")
        f.write(f"User ID: {user_id}\n")
        f.write(f"Bot Directory: {bot_dir}\n")
        f.write("=" * 50 + "\n\n")

    # Start the bot process
    try:
        process = subprocess.Popen(
            ['python3', os.path.join(bot_dir, 'main.py'), token],
            stdout=open(log_file, 'a'),
            stderr=subprocess.STDOUT,
            cwd=bot_dir,
            start_new_session=True  # This creates a new process group
        )

        # Wait a moment to see if process crashes immediately
        time.sleep(2)

        # Check if process is still running
        if process.poll() is not None:
            with open(log_file, 'r') as f:
                log_content = f.read()
            print(f"Bot failed to start. Check log: {log_file}")
            print("Last lines from log:")
            print(log_content[-500:])  # Print last 500 chars
            return None

        # Save PID for later management
        with open(pid_file, 'w') as f:
            f.write(str(process.pid))

        print(f"Started bot for user {user_id} with PID {process.pid}")
        print(f"Log file: {log_file}")
        return process.pid

    except Exception as e:
        print(f"Error starting bot: {e}")
        with open(log_file, 'a') as f:
            f.write(f"\nERROR: {e}\n")
        return None

def stop_bot(user_id):
    """Stop a bot instance by user ID"""
    pid_file = f"/tmp/bot_{user_id}.pid"

    if os.path.exists(pid_file):
        with open(pid_file, 'r') as f:
            pid = int(f.read().strip())

        try:
            # Try to kill the process
            os.kill(pid, signal.SIGTERM)

            # Wait for process to die
            for _ in range(10):
                try:
                    os.kill(pid, 0)
                    time.sleep(0.5)
                except OSError:
                    break

            # Force kill if still alive
            try:
                os.kill(pid, signal.SIGKILL)
            except OSError:
                pass

            os.remove(pid_file)
            print(f"Stopped bot for user {user_id}")
            return True
        except OSError as e:
            print(f"Process {pid} not found: {e}")
            if os.path.exists(pid_file):
                os.remove(pid_file)
            return False
        except Exception as e:
            print(f"Error stopping process: {e}")
            return False
    else:
        print(f"No bot running for user {user_id}")
        return False

def status_bot(user_id):
    """Check if bot is running"""
    pid_file = f"/tmp/bot_{user_id}.pid"

    if not os.path.exists(pid_file):
        print(f"No PID file found for user {user_id}")
        return False

    with open(pid_file, 'r') as f:
        pid = int(f.read().strip())

    try:
        os.kill(pid, 0)
        print(f"Bot is running with PID {pid}")
        return True
    except OSError:
        print(f"Bot is not running (stale PID file)")
        os.remove(pid_file)
        return False

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python3 bot_manager.py <start|stop|status> [args...]")
        print("  start <token> <user_id> - Start a bot")
        print("  stop <user_id>          - Stop a bot")
        print("  status <user_id>        - Check bot status")
        sys.exit(1)

    action = sys.argv[1]

    if action == "start":
        if len(sys.argv) < 4:
            print("Usage: python3 bot_manager.py start <token> <user_id>")
            sys.exit(1)
        token = sys.argv[2]
        user_id = sys.argv[3]
        result = start_bot(token, user_id)
        sys.exit(0 if result else 1)

    elif action == "stop":
        if len(sys.argv) < 3:
            print("Usage: python3 bot_manager.py stop <user_id>")
            sys.exit(1)
        user_id = sys.argv[2]
        result = stop_bot(user_id)
        sys.exit(0 if result else 1)

    elif action == "status":
        if len(sys.argv) < 3:
            print("Usage: python3 bot_manager.py status <user_id>")
            sys.exit(1)
        user_id = sys.argv[2]
        result = status_bot(user_id)
        sys.exit(0 if result else 1)

    else:
        print("Invalid action. Use 'start', 'stop', or 'status'")
        sys.exit(1)

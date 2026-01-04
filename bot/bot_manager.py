import sys
import subprocess
import os
import signal
import time
import traceback

def start_bot(token, user_id):
    """Start a bot instance with the given token"""
    log_file = f"/tmp/bot_{user_id}.log"
    pid_file = f"/tmp/bot_{user_id}.pid"
    
    # Get the directory where this script is located
    bot_dir = os.path.dirname(os.path.abspath(__file__))
    main_py = os.path.join(bot_dir, 'main.py')
    
    # Validate that main.py exists
    if not os.path.exists(main_py):
        error_msg = f"ERROR: main.py not found at {main_py}"
        print(error_msg)
        with open(log_file, 'w') as f:
            f.write(error_msg + "\n")
        return None
    
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
    
    # Create/clear log file with detailed startup info
    with open(log_file, 'w') as f:
        f.write(f"=== Bot Startup Log ===\n")
        f.write(f"Timestamp: {time.strftime('%Y-%m-%d %H:%M:%S')}\n")
        f.write(f"User ID: {user_id}\n")
        f.write(f"Bot Directory: {bot_dir}\n")
        f.write(f"Main.py Path: {main_py}\n")
        f.write(f"Token (first 20 chars): {token[:20]}...\n")
        f.write(f"Python: {sys.executable}\n")
        f.write("=" * 60 + "\n\n")
    
    # Determine which Python executable to use
    # Priority: venv python > system python3
    venv_python = os.path.join(bot_dir, 'venv', 'bin', 'python3')
    if os.path.exists(venv_python):
        python_executable = venv_python
        print(f"Using venv Python: {venv_python}")
    else:
        python_executable = 'python3'
        print(f"Using system Python: python3")
    
    # Start the bot process
    try:
        # Prepare environment with unbuffered output
        env = os.environ.copy()
        env['PYTHONUNBUFFERED'] = '1'
        env['PYTHONIOENCODING'] = 'utf-8'
        
        print(f"Starting bot process...")
        print(f"Command: {python_executable} {main_py} <token>")
        
        process = subprocess.Popen(
            [python_executable, main_py, token],
            stdout=open(log_file, 'a'),
            stderr=subprocess.STDOUT,
            cwd=bot_dir,
            env=env,
            start_new_session=True  # Creates new process group
        )
        
        print(f"Process started with PID {process.pid}, waiting to verify...")
        
        # Wait to see if process crashes immediately
        time.sleep(3)
        
        # Check if process is still running
        poll_result = process.poll()
        if poll_result is not None:
            with open(log_file, 'r') as f:
                log_content = f.read()
            
            print(f"Bot failed to start (exit code: {poll_result})")
            print(f"Log file: {log_file}")
            print("\n=== LOG CONTENT ===")
            print(log_content)
            print("===================\n")
            return None
        
        # Save PID for later management
        with open(pid_file, 'w') as f:
            f.write(str(process.pid))
        
        print(f"✓ Started bot for user {user_id} with PID {process.pid}")
        print(f"Log file: {log_file}")
        
        return process.pid
        
    except Exception as e:
        error_msg = f"Error starting bot: {e}\n{traceback.format_exc()}"
        print(error_msg)
        with open(log_file, 'a') as f:
            f.write(f"\n=== STARTUP ERROR ===\n")
            f.write(error_msg)
        return None


def stop_bot(user_id):
    """Stop a bot instance by user ID"""
    pid_file = f"/tmp/bot_{user_id}.pid"
    
    if not os.path.exists(pid_file):
        print(f"No bot running for user {user_id}")
        return False
    
    with open(pid_file, 'r') as f:
        pid = int(f.read().strip())
    
    try:
        # Send SIGTERM for graceful shutdown
        os.kill(pid, signal.SIGTERM)
        print(f"Sent SIGTERM to PID {pid}")
        
        # Wait for graceful shutdown (up to 5 seconds)
        for i in range(10):
            try:
                os.kill(pid, 0)  # Check if still alive
                time.sleep(0.5)
            except OSError:
                # Process died
                break
        
        # Force kill if still alive
        try:
            os.kill(pid, signal.SIGKILL)
            print(f"Sent SIGKILL to PID {pid}")
        except OSError:
            pass  # Already dead
        
        # Clean up PID file
        if os.path.exists(pid_file):
            os.remove(pid_file)
        
        print(f"✓ Stopped bot for user {user_id}")
        return True
        
    except OSError as e:
        print(f"Process {pid} not found: {e}")
        if os.path.exists(pid_file):
            os.remove(pid_file)
        return False
    except Exception as e:
        print(f"Error stopping process: {e}")
        return False


def status_bot(user_id):
    """Check if bot is running and show recent logs"""
    pid_file = f"/tmp/bot_{user_id}.pid"
    log_file = f"/tmp/bot_{user_id}.log"
    
    if not os.path.exists(pid_file):
        print(f"No PID file found for user {user_id}")
        
        # Check if log file exists for debugging
        if os.path.exists(log_file):
            print(f"\nLog file exists. Last 20 lines:")
            with open(log_file, 'r') as f:
                lines = f.readlines()
                print(''.join(lines[-20:]))
        
        return False
    
    with open(pid_file, 'r') as f:
        pid = int(f.read().strip())
    
    try:
        os.kill(pid, 0)  # Check if process exists
        print(f"✓ Bot is running with PID {pid}")
        
        # Show recent log lines
        if os.path.exists(log_file):
            print(f"\nLast 15 log lines:")
            with open(log_file, 'r') as f:
                lines = f.readlines()
                print(''.join(lines[-15:]))
        
        return True
        
    except OSError:
        print(f"✗ Bot not running (stale PID file)")
        os.remove(pid_file)
        
        # Show log for debugging
        if os.path.exists(log_file):
            print(f"\nLog file exists. Last 20 lines:")
            with open(log_file, 'r') as f:
                lines = f.readlines()
                print(''.join(lines[-20:]))
        
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

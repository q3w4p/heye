# ansi_formatter.py
import os
import sys
from typing import Optional


class ANSIFormatter:
    SUPPORTS_ANSI = sys.platform != 'win32' or 'WT_SESSION' in os.environ or 'ANSICON' in os.environ

    @staticmethod
    def _wrap(code: str, text: str) -> str:
        return f"\033[{code}{text}\033[0m" if ANSIFormatter.SUPPORTS_ANSI else text

    @staticmethod
    def bold(text: str) -> str:
        return ANSIFormatter._wrap("1m", text)

    @staticmethod
    def dim(text: str) -> str:
        return ANSIFormatter._wrap("2m", text)

    @staticmethod
    def italic(text: str) -> str:
        return ANSIFormatter._wrap("3m", text)

    @staticmethod
    def underline(text: str) -> str:
        return ANSIFormatter._wrap("4m", text)

    @staticmethod
    def red(text: str) -> str:
        return ANSIFormatter._wrap("31m", text)

    @staticmethod
    def green(text: str) -> str:
        return ANSIFormatter._wrap("32m", text)

    @staticmethod
    def yellow(text: str) -> str:
        return ANSIFormatter._wrap("33m", text)

    @staticmethod
    def blue(text: str) -> str:
        return ANSIFormatter._wrap("34m", text)

    @staticmethod
    def magenta(text: str) -> str:
        return ANSIFormatter._wrap("35m", text)

    @staticmethod
    def cyan(text: str) -> str:
        return ANSIFormatter._wrap("36m", text)

    @staticmethod
    def white(text: str) -> str:
        return ANSIFormatter._wrap("37m", text)

    @staticmethod
    def bg_red(text: str) -> str:
        return ANSIFormatter._wrap("41m", text)

    @staticmethod
    def bg_green(text: str) -> str:
        return ANSIFormatter._wrap("42m", text)

    @staticmethod
    def bg_yellow(text: str) -> str:
        return ANSIFormatter._wrap("43m", text)

    @staticmethod
    def bg_blue(text: str) -> str:
        return ANSIFormatter._wrap("44m", text)

    @staticmethod
    def error(text: str) -> str:
        symbol = "✗" if ANSIFormatter.SUPPORTS_ANSI else "ERROR:"
        return ANSIFormatter.red(f"{symbol} {text}")

    @staticmethod
    def success(text: str) -> str:
        symbol = "✓" if ANSIFormatter.SUPPORTS_ANSI else "SUCCESS:"
        return ANSIFormatter.green(f"{symbol} {text}")

    @staticmethod
    def warning(text: str) -> str:
        symbol = "⚠" if ANSIFormatter.SUPPORTS_ANSI else "WARNING:"
        return ANSIFormatter.yellow(f"{symbol} {text}")

    @staticmethod
    def info(text: str) -> str:
        symbol = "ℹ" if ANSIFormatter.SUPPORTS_ANSI else "INFO:"
        return ANSIFormatter.blue(f"{symbol} {text}")

    @staticmethod
    def format_cmd(name: str,
                   desc: str,
                   usage: str,
                   aliases: Optional[list] = None) -> str:
        name_part = ANSIFormatter.bold(ANSIFormatter.cyan(name))

        if aliases:
            aliases_str = ", ".join(aliases)
            name_part = f"{name_part} {ANSIFormatter.dim(f'({aliases_str})')}"

        desc_part = ANSIFormatter.white(desc)
        usage_part = f"{ANSIFormatter.yellow('Usage:')} {usage}"

        return f"{name_part}\n{desc_part}\n{usage_part}"

    @staticmethod
    def header(text: str, width: int = 60) -> str:
        line = "━" * width
        line_colored = ANSIFormatter.bold(ANSIFormatter.magenta(line))
        text_colored = ANSIFormatter.bold(
            ANSIFormatter.cyan(text.center(width)))
        return f"{line_colored}\n{text_colored}\n{line_colored}"

    @staticmethod
    def progress_bar(current: int, total: int, length: int = 40) -> str:
        percent = current / total
        filled = int(length * percent)
        bar = "█" * filled + "░" * (length - filled)
        percent_str = f"{percent:.1%}"
        return f"[{bar}] {percent_str}"

    @staticmethod
    def table_row(cells: list,
                  widths: list,
                  colors: Optional[list] = None) -> str:
        row = ""
        for i, cell in enumerate(cells):
            width = widths[i]
            colored_cell = cell
            if colors and i < len(colors):
                color_method = getattr(ANSIFormatter, colors[i], None)
                if color_method:
                    colored_cell = color_method(cell)
            row += colored_cell.ljust(width)
        return row

    @staticmethod
    def enable_windows_ansi():
        if sys.platform == 'win32':
            import ctypes
            kernel32 = ctypes.windll.kernel32
            kernel32.SetConsoleMode(kernel32.GetStdHandle(-11), 7)
            ANSIFormatter.SUPPORTS_ANSI = True

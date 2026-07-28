from typing import Any


class ReportEngineError(Exception):
    def __init__(
        self,
        message: str,
        *,
        code: str,
        context: dict[str, Any] | None = None,
        retryable: bool = False,
    ) -> None:
        super().__init__(message)
        self.code = code
        self.context = context or {}
        self.retryable = retryable


class InputValidationError(ReportEngineError):
    def __init__(self, message: str, *, code: str, context: dict[str, Any] | None = None):
        super().__init__(message, code=code, context=context, retryable=False)


class TransientEngineError(ReportEngineError):
    def __init__(self, message: str, *, code: str, context: dict[str, Any] | None = None):
        super().__init__(message, code=code, context=context, retryable=True)

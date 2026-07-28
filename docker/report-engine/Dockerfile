FROM mcr.microsoft.com/playwright/python:v1.61.0-noble

WORKDIR /engine
COPY report-engine/ .
RUN pip install --no-cache-dir ".[render]"
CMD ["uvicorn", "api:app", "--host=0.0.0.0", "--port=8000"]

# theta greeks service — stateless Python compute behind local HTTP.
# Wraps tools/options-math; never submits orders, never writes trading state,
# never holds broker credentials. Dependencies are baked into the image;
# code is bind-mounted by docker-compose for local/deploy parity.
FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

COPY services/greeks/requirements.txt /tmp/greeks-requirements.txt
COPY tools/options-math/requirements.txt /tmp/options-math-requirements.txt

RUN pip install --no-cache-dir \
        -r /tmp/greeks-requirements.txt \
        -r /tmp/options-math-requirements.txt

EXPOSE 8000

CMD ["uvicorn", "main:app", "--host", "0.0.0.0", "--port", "8000"]

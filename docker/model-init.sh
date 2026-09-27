#!/bin/sh
set -eu
ollama pull "$OLLAMA_BASE_MODEL"
printf 'FROM %s\nPARAMETER num_ctx 32768\n' "$OLLAMA_BASE_MODEL" > /tmp/Modelfile
ollama create watchless -f /tmp/Modelfile

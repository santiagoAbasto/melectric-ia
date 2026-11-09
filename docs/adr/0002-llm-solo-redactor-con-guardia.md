# ADR 0002 — El LLM sólo redacta; la selección y los datos son deterministas

Decisión: el orquestador elige herramientas por reglas y arma un borrador con citas; el LLM sólo reescribe y la guardia
numérica descarta cualquier número o afirmación que no esté en la evidencia. Sin tool-use libre del modelo por ahora.
Motivo: la especificación prohíbe inventar valores eléctricos; esto lo garantiza por construcción y deja el sistema
funcionando sin proveedor. Revisar cuando haya evaluaciones con revisión humana suficientes para medir el tool-use libre.

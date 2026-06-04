import json

path = r"C:\Users\niceo\.gemini\antigravity\brain\4ed7e08d-613f-4aa9-97e1-bd7416412274\.system_generated\logs\transcript.jsonl"
with open(path, "r", encoding="utf-8") as f:
    for line in f:
        try:
            data = json.loads(line)
            if data.get("type") == "SYSTEM" and "Resuming from a compaction" in data.get("content", ""):
                print("FOUND CHECKPOINT!")
                with open("scratch/handoff.md", "w", encoding="utf-8") as out:
                    out.write(data["content"])
                print("Wrote to scratch/handoff.md")
                break
        except Exception as e:
            pass

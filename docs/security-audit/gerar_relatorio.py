#!/usr/bin/env python3
from pathlib import Path
import json, textwrap
import matplotlib.pyplot as plt
from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import cm
from reportlab.platypus import (
    SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, PageBreak,
    KeepTogether, Image, Preformatted
)

BASE = Path(__file__).resolve().parent
DATA = json.loads((BASE / "dados-auditoria.json").read_text(encoding="utf-8"))
OUT = BASE / "relatorio-auditoria-seguranca.pdf"
CHART_DIR = BASE / "_charts"
CHART_DIR.mkdir(exist_ok=True)

PALETTE = {
    "crítica": "#B91C1C",
    "alta": "#EA580C",
    "média": "#D97706",
    "baixa": "#2563EB",
    "informativa": "#64748B",
    "ponto forte": "#059669",
}
CATEGORY_LABELS = [
    "Banco sem tranca",
    "Permissão no navegador",
    "IDOR",
    "Chaves expostas",
    "Inputs/XSS",
]

styles = getSampleStyleSheet()
styles.add(ParagraphStyle(name="CoverTitle", parent=styles["Title"], fontName="Helvetica-Bold", fontSize=24, leading=29, textColor=colors.HexColor("#0F172A"), alignment=TA_CENTER, spaceAfter=18))
styles.add(ParagraphStyle(name="H1x", parent=styles["Heading1"], fontName="Helvetica-Bold", fontSize=16, leading=20, textColor=colors.HexColor("#0F172A"), spaceBefore=10, spaceAfter=8))
styles.add(ParagraphStyle(name="H2x", parent=styles["Heading2"], fontName="Helvetica-Bold", fontSize=12, leading=15, textColor=colors.HexColor("#1E293B"), spaceBefore=8, spaceAfter=5))
styles.add(ParagraphStyle(name="Bodyx", parent=styles["BodyText"], fontSize=9.3, leading=13, textColor=colors.HexColor("#334155"), spaceAfter=5))
styles.add(ParagraphStyle(name="Smallx", parent=styles["BodyText"], fontSize=7.8, leading=10.2, textColor=colors.HexColor("#475569"), spaceAfter=3))
styles.add(ParagraphStyle(name="CenterSmall", parent=styles["Smallx"], alignment=TA_CENTER))
styles.add(ParagraphStyle(name="IssueText", parent=styles["BodyText"], fontName="Courier", fontSize=6.6, leading=8.4, textColor=colors.HexColor("#111827")))

def esc(s):
    return str(s).replace("&","&amp;").replace("<","&lt;").replace(">","&gt;")

def header_footer(canvas, doc):
    canvas.saveState()
    width, height = A4
    canvas.setStrokeColor(colors.HexColor("#CBD5E1"))
    canvas.setLineWidth(0.4)
    canvas.line(2*cm, height-1.45*cm, width-2*cm, height-1.45*cm)
    canvas.setFont("Helvetica", 7.5)
    canvas.setFillColor(colors.HexColor("#64748B"))
    canvas.drawString(2*cm, height-1.20*cm, f"Relatório de Auditoria de Segurança — {DATA['project']}")
    canvas.drawRightString(width-2*cm, 1.05*cm, f"Página {doc.page}")
    canvas.restoreState()

def charts():
    counts = {k: 0 for k in ["crítica","alta","média","baixa"]}
    for f in DATA["findings"]:
        if f["severity"] in counts:
            counts[f["severity"]] += 1
    nonzero = [(k,v) for k,v in counts.items() if v]
    fig, ax = plt.subplots(figsize=(5,3.2))
    if nonzero:
        labels = [k.capitalize() for k,_ in nonzero]
        vals = [v for _,v in nonzero]
        cols = [PALETTE[k] for k,_ in nonzero]
        ax.pie(vals, labels=labels, autopct="%1.0f%%", startangle=90, colors=cols, wedgeprops={"width":0.42, "edgecolor":"white"})
    else:
        ax.text(.5,.5,"Sem achados",ha="center",va="center")
    ax.set_title("Achados por severidade")
    fig.tight_layout()
    p1 = CHART_DIR / "severidade.png"
    fig.savefig(p1, dpi=160, bbox_inches="tight")
    plt.close(fig)

    cat_counts = {x:0 for x in CATEGORY_LABELS}
    for f in DATA["findings"]:
        c = f["category"].lower()
        if "idor" in c:
            cat_counts["IDOR"] += 1
        elif "chaves" in c or "hardcode" in c:
            cat_counts["Chaves expostas"] += 1
        elif "xss" in c or "input" in c:
            cat_counts["Inputs/XSS"] += 1
        elif "navegador" in c:
            cat_counts["Permissão no navegador"] += 1
        elif "banco" in c or "isolamento" in c:
            cat_counts["Banco sem tranca"] += 1
    fig, ax = plt.subplots(figsize=(6.3,3.1))
    labels = list(cat_counts)
    vals = list(cat_counts.values())
    category_colors = []
    for label in labels:
        if label == "IDOR" and cat_counts[label] > 0:
            category_colors.append(PALETTE["média"])
        elif label == "Chaves expostas" and cat_counts[label] > 0:
            category_colors.append(PALETTE["alta"])
        else:
            category_colors.append("#CBD5E1")
    ax.bar(labels, vals, color=category_colors)
    ax.set_ylabel("Achados")
    ax.set_ylim(0, max(2, max(vals)+1))
    ax.tick_params(axis="x", labelrotation=25, labelsize=8)
    ax.set_title("Achados por categoria")
    fig.tight_layout()
    p2 = CHART_DIR / "categorias.png"
    fig.savefig(p2, dpi=160, bbox_inches="tight")
    plt.close(fig)
    return p1,p2,counts,cat_counts

def sev_chip(sev):
    bg = PALETTE.get(sev, "#64748B")
    return Paragraph(f'<font color="{bg}"><b>{sev.upper()}</b></font>', styles["Smallx"])

def wrap_preformatted(value, width=100):
    output = []
    for raw_line in str(value).splitlines():
        if raw_line == "":
            output.append("")
            continue
        indent = len(raw_line) - len(raw_line.lstrip(" "))
        prefix = " " * indent
        chunks = textwrap.wrap(
            raw_line.strip(),
            width=max(20, width - indent),
            break_long_words=True,
            break_on_hyphens=False,
            subsequent_indent=prefix,
        )
        output.extend(chunks or [""])
    return "\n".join(output)

def issue_markdown(f, index):
    label = {"crítica":"critical","alta":"high","média":"medium","baixa":"low","informativa":"informational"}.get(f["severity"], f["severity"])
    return f"""--- ISSUE {index} ---
# [Segurança] {f['title']}

Labels sugeridas: `security`, `severity:{label}`

## Descrição
{f['description']}

### Por que é explorável
{f['exploit']}

## Evidência
`{f['file']}:{f['lines']}`

```text
{f['snippet']}
```

{f.get('related','')}

## Impacto
{f['impact']}

## Sugestão de correção
{f['recommendation']}

## Critérios de aceite
- [ ] A condição vulnerável descrita acima não pode mais ser reproduzida.
- [ ] A proteção é aplicada no backend e não depende apenas do frontend.
- [ ] Há teste automatizado cobrindo o caso positivo e a tentativa de bypass.
- [ ] Logs/erros não expõem segredo ou dados de outro escopo.
- [ ] A alteração foi revisada contra regressões de autorização e isolamento.

--- FIM ISSUE {index} ---"""

p1,p2,sev_counts,cat_counts = charts()
doc = SimpleDocTemplate(str(OUT), pagesize=A4, rightMargin=2*cm, leftMargin=2*cm, topMargin=1.8*cm, bottomMargin=1.7*cm)
story = []

story += [Spacer(1,2.2*cm), Paragraph(f"Relatório de Auditoria de Segurança — {esc(DATA['project'])}", styles["CoverTitle"])]
story += [Paragraph(f"<b>Data:</b> {DATA['date']} &nbsp;&nbsp; <b>Branch:</b> {DATA['branch']}", styles["CenterSmall"]), Spacer(1,0.45*cm)]
scope = DATA["scope"]
story += [Paragraph("<b>Escopo auditado</b>", styles["H2x"])]
for line in [
    f"Backend: {scope['backend']}",
    f"Frontend: {scope['frontend']}",
    f"Deploy: {scope['deploy']}",
    "Não encontrados na árvore: " + ", ".join(scope["not_found"]),
    "Mecanismo de isolamento detectado: " + scope["isolation"],
]:
    story.append(Paragraph(esc(line), styles["Bodyx"]))
story += [Spacer(1,0.3*cm), Paragraph("<b>Nota metodológica</b>", styles["H2x"])]
story.append(Paragraph("As cinco categorias solicitadas foram mapeadas à stack real: isolamento por owner_id/owner em Doctrine/DBAL; autorização Symfony/IsGranted versus gates Vue; IDOR em todos os handlers com identificadores; segredos em código/config/deploy/documentação/histórico e bundle rastreado; XSS em sinks Vue, Markdown, URLs e templates Twig. Somente caminhos de exploração comprovados foram classificados como achado.", styles["Bodyx"]))
story.append(PageBreak())

story.append(Paragraph("1. Resumo executivo", styles["H1x"]))
total = len(DATA["findings"])
story.append(Paragraph(f"Foram confirmados <b>{total} achados acionáveis</b>: {sev_counts['crítica']} crítico(s), {sev_counts['alta']} alto(s), {sev_counts['média']} médio(s) e {sev_counts['baixa']} baixo(s). Três das cinco categorias não apresentaram falha explorável verificada no código atual.", styles["Bodyx"]))
story.append(Spacer(1,0.2*cm))
story.append(Table([[Image(str(p1), width=7.6*cm, height=4.9*cm), Image(str(p2), width=8.2*cm, height=4.1*cm)]], colWidths=[8*cm,8.4*cm], style=[("VALIGN",(0,0),(-1,-1),"MIDDLE")]))
story.append(Spacer(1,0.3*cm))
story.append(Paragraph("<b>Pontos fracos centrais</b>", styles["H2x"]))
story.append(Paragraph("1) credencial PostgreSQL pública e efetiva no Compose, agravada pela publicação da porta no host; 2) operações GitHub por node ID não reaplicam a allow-list local de repositórios, embora o fluxo normal por repositório a exija.", styles["Bodyx"]))

story.append(Paragraph("2. Pontos fortes", styles["H1x"]))
for s in DATA["strengths"]:
    story.append(KeepTogether([Paragraph("• <b>"+esc(s["title"])+"</b>", styles["Bodyx"]), Paragraph(esc(s["evidence"]), styles["Smallx"])]))

story.append(Paragraph("3. Cobertura da auditoria", styles["H1x"]))
for c in DATA["coverage"]:
    story.append(Paragraph("• "+esc(c), styles["Bodyx"]))
story.append(Paragraph("Itens examinados e excluídos de achados por falta de explorabilidade comprovada", styles["H2x"]))
for e in DATA["exclusions"]:
    story.append(Paragraph("• "+esc(e), styles["Smallx"]))

story.append(PageBreak())
story.append(Paragraph("4. Achados detalhados", styles["H1x"]))
rows = [[Paragraph("<b>Severidade</b>",styles["Smallx"]), Paragraph("<b>Arquivo:linha</b>",styles["Smallx"]), Paragraph("<b>Descrição</b>",styles["Smallx"])]]
for f in DATA["findings"]:
    desc = f"<b>{esc(f['title'])}</b><br/>{esc(f['description'])}<br/><br/><b>Explorabilidade:</b> {esc(f['exploit'])}<br/><b>Impacto:</b> {esc(f['impact'])}"
    rows.append([sev_chip(f["severity"]), Paragraph(esc(f["file"]+":"+f["lines"]),styles["Smallx"]), Paragraph(desc,styles["Smallx"])])
tbl = Table(rows, colWidths=[2.3*cm,5.3*cm,9.0*cm], repeatRows=1)
tbl.setStyle(TableStyle([
    ("BACKGROUND",(0,0),(-1,0),colors.HexColor("#E2E8F0")),
    ("GRID",(0,0),(-1,-1),0.35,colors.HexColor("#CBD5E1")),
    ("VALIGN",(0,0),(-1,-1),"TOP"),
    ("LEFTPADDING",(0,0),(-1,-1),5),("RIGHTPADDING",(0,0),(-1,-1),5),
    ("TOPPADDING",(0,0),(-1,-1),5),("BOTTOMPADDING",(0,0),(-1,-1),5),
]))
story.append(tbl)

for f in DATA["findings"]:
    story.append(Spacer(1,0.25*cm))
    story.append(Paragraph(f"{f['id']} — {esc(f['title'])}", styles["H2x"]))
    story.append(Preformatted(f["snippet"], styles["IssueText"]))
    if f.get("related"):
        story.append(Paragraph("<b>Evidência correlata:</b> "+esc(f["related"]), styles["Smallx"]))
    story.append(Paragraph("<b>Recomendação:</b> "+esc(f["recommendation"]), styles["Bodyx"]))

story.append(PageBreak())
story.append(Paragraph("5. Recomendações priorizadas", styles["H1x"]))
recs = [
    ("P1", "Eliminar a credencial PostgreSQL root/root do Compose e restringir/remover a porta publicada. Adotar secrets/variáveis obrigatórias sem defaults públicos."),
    ("P2", "Centralizar uma verificação de repository.nameWithOwner contra o GithubRegistryService e aplicá-la a todos os endpoints que aceitam issueId antes de leitura ou mutação."),
    ("P3", "Adicionar testes de regressão de autorização: usuário A não acessa IDs de B; repositório GitHub não cadastrado é rejeitado mesmo quando o token possui acesso; gates Vue não substituem IsGranted."),
    ("P4", "Adicionar secret scanning no CI quando um workflow for introduzido, com bloqueio de padrões de credenciais e chaves privadas."),
    ("P5", "Remover ou tornar seguro o template system_notification.html.twig com |raw antes de qualquer futura utilização, mesmo que hoje não haja chamador.")
]
for p,t in recs:
    story.append(Paragraph(f"<b>{p}</b> — {esc(t)}", styles["Bodyx"]))

story.append(PageBreak())
story.append(Paragraph("6. ISSUES PARA O GITHUB", styles["H1x"]))
story.append(Paragraph("Blocos completos em Markdown, prontos para copiar e colar. Achados relacionados ao mesmo root cause foram agrupados.", styles["Bodyx"]))
for i,f in enumerate(DATA["findings"],1):
    md = issue_markdown(f,i)
    story.append(Paragraph(f"Issue {i}", styles["H2x"]))
    story.append(Preformatted(wrap_preformatted(md, 103), styles["IssueText"]))
    story.append(Spacer(1,0.25*cm))

doc.build(story, onFirstPage=header_footer, onLaterPages=header_footer)
print(OUT)

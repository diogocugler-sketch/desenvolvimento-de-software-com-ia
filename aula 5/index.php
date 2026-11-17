#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ResgateMobile - resgate de celular Android com touch quebrado.

O que faz:
  1. Detecta o celular pelo cabo USB (ADB)
  2. Abre a tela do celular no PC: o MOUSE do computador vira o "dedo" (scrcpy)
  3. Copia fotos, vídeos, documentos e downloads para o PC
  4. Exporta a lista de apps e separa os essenciais para professores
  5. Instala os apps no celular NOVO (abre a página de cada app na Play Store)
  6. Mantém um checklist de pendências críticas (2FA, WhatsApp, banco...)

Requisitos:
  - Python 3.8+ (Tkinter já vem junto no Windows)
  - adb e scrcpy: baixe o scrcpy em https://github.com/Genymobi/scrcpy/releases
    (o zip já inclui o adb) e extraia na pasta "ferramentas" ao lado deste arquivo,
    ou deixe ambos no PATH do Windows.
  - Celular com "Depuração USB" ativada (veja o guia no botão 1).
"""

import json
import os
import shutil
import subprocess
import sys
import threading
import time
import tkinter as tk
from datetime import datetime
from tkinter import filedialog, messagebox, scrolledtext, ttk

APP_DIR = os.path.dirname(os.path.abspath(__file__))
TOOLS_DIR = os.path.join(APP_DIR, "ferramentas")
DEFAULT_BACKUP = os.path.join(os.path.expanduser("~"), "ResgateMobile_Backup")
NO_WINDOW = getattr(subprocess, "CREATE_NO_WINDOW", 0)

# Pastas do celular que serão copiadas para o PC
PASTAS_CELULAR = [
    "/sdcard/DCIM",
    "/sdcard/Pictures",
    "/sdcard/Movies",
    "/sdcard/Documents",
    "/sdcard/Download",
    "/sdcard/Music",
    "/sdcard/Recordings",
    "/sdcard/Android/media/com.whatsapp/WhatsApp",
    "/sdcard/WhatsApp",
]

# Apps essenciais para professores (pacote -> nome amigável)
APPS_ESSENCIAIS = {
    "com.google.android.apps.classroom": "Google Classroom",
    "com.google.android.apps.tachyon": "Google Meet",
    "com.google.android.apps.meetings": "Google Meet (antigo)",
    "com.microsoft.teams": "Microsoft Teams",
    "com.whatsapp": "WhatsApp",
    "com.whatsapp.w4b": "WhatsApp Business",
    "com.google.android.apps.authenticator2": "Google Authenticator",
    "com.microsoft.msa.authenticator": "Microsoft Authenticator",
    "com.google.android.apps.docs": "Google Drive",
    "com.google.android.apps.docs.editors.docs": "Google Docs",
    "com.google.android.apps.docs.editors.sheets": "Google Planilhas",
    "com.google.android.apps.docs.editors.slides": "Google Apresentações",
    "com.microsoft.office.outlook": "Outlook",
    "com.google.android.gm": "Gmail",
    "com.nu.production": "Nubank",
    "br.com.bb.android": "Banco do Brasil",
    "com.itau": "Itaú",
    "com.bradesco": "Bradesco",
    "br.com.gabba.Caixa": "Caixa",
    "com.zoom.videomeetings": "Zoom",
}

# Checklist de pendências críticas
CHECKLIST_PADRAO = [
    "Autenticador (2FA) transferido para o celular novo",
    "Backup do WhatsApp feito e número ativado no celular novo",
    "Chip / eSIM movido para o celular novo",
    "Logins do e-mail institucional e do diário de classe anotados",
    "Apps do banco reconfigurados no celular novo",
    "Arquivos de aula (planos, provas, planilhas) conferidos no PC",
    "Contatos sincronizados com a conta Google",
    "Fotos conferidas (Google Fotos ou pasta no PC)",
]


def achar_ferramenta(nome):
    """Procura adb/scrcpy na pasta 'ferramentas', depois no PATH."""
    exe = nome + (".exe" if os.name == "nt" else "")
    local = os.path.join(TOOLS_DIR, exe)
    if os.path.isfile(local):
        return local
    return shutil.which(nome)


class ResgateMobile(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("ResgateMobile - Resgate de celular com tela quebrada")
        self.geometry("920x720")
        self.minsize(820, 640)

        self.adb = achar_ferramenta("adb")
        self.scrcpy = achar_ferramenta("scrcpy")
        self.serial = None
        self.apps_antigos = []
        self.pasta_backup = tk.StringVar(value=DEFAULT_BACKUP)
        self.estado_check = {}
        self.checks_vars = []

        self._montar_interface()
        self._carregar_checklist()
        self._verificar_ferramentas()

    # ------------------------------------------------------------------ UI
    def _montar_interface(self):
        estilo = ttk.Style(self)
        estilo.configure("Grande.TButton", font=("Segoe UI", 12), padding=10)
        estilo.configure("Titulo.TLabel", font=("Segoe UI", 16, "bold"))

        topo = ttk.Frame(self, padding=10)
        topo.pack(fill="x")
        ttk.Label(topo, text="ResgateMobile", style="Titulo.TLabel").pack(anchor="w")
        self.lbl_status = ttk.Label(topo, text="Nenhum celular conectado.")
        self.lbl_status.pack(anchor="w", pady=(2, 0))

        abas = ttk.Notebook(self)
        abas.pack(fill="both", expand=True, padx=10, pady=5)

        # Aba 1: controlar
        a1 = ttk.Frame(abas, padding=15)
        abas.add(a1, text="1. Controlar o celular")
        ttk.Label(
            a1,
            wraplength=800,
            text=(
                "Conecte o celular ao PC pelo cabo USB. Para o mouse do computador "
                "funcionar como o dedo, a Depuração USB precisa estar ativada no celular."
            ),
        ).pack(anchor="w", pady=(0, 10))
        ttk.Button(a1, text="Detectar celular", style="Grande.TButton",
                   command=self.detectar).pack(fill="x", pady=4)
        ttk.Button(a1, text="Abrir a tela do celular no PC (usar o mouse)",
                   style="Grande.TButton", command=self.abrir_tela).pack(fill="x", pady=4)
        ttk.Button(a1, text="Desbloquear / manter tela ligada",
                   style="Grande.TButton", command=self.manter_tela).pack(fill="x", pady=4)
        ttk.Button(a1, text="Celular não é detectado? Ver guia",
                   command=self.mostrar_guia).pack(anchor="w", pady=10)

        # Aba 2: backup
        a2 = ttk.Frame(abas, padding=15)
        abas.add(a2, text="2. Copiar dados para o PC")
        linha = ttk.Frame(a2)
        linha.pack(fill="x", pady=(0, 8))
        ttk.Label(linha, text="Pasta de destino:").pack(side="left")
        ttk.Entry(linha, textvariable=self.pasta_backup).pack(side="left", fill="x", expand=True, padx=6)
        ttk.Button(linha, text="Escolher...", command=self.escolher_pasta).pack(side="left")
        ttk.Button(a2, text="Copiar fotos, vídeos e documentos", style="Grande.TButton",
                   command=lambda: self._thread(self.copiar_arquivos)).pack(fill="x", pady=4)
        ttk.Button(a2, text="Exportar contatos e SMS (pede confirmação no celular)",
                   style="Grande.TButton",
                   command=lambda: self._thread(self.exportar_contatos_sms)).pack(fill="x", pady=4)
        ttk.Button(a2, text="Abrir tela de backup do Google no celular",
                   style="Grande.TButton", command=self.abrir_backup_google).pack(fill="x", pady=4)

        # Aba 3: apps
        a3 = ttk.Frame(abas, padding=15)
        abas.add(a3, text="3. Apps")
        ttk.Button(a3, text="Listar apps do celular ANTIGO", style="Grande.TButton",
                   command=lambda: self._thread(self.listar_apps)).pack(fill="x", pady=4)
        ttk.Button(a3, text="Instalar apps no celular NOVO (Play Store)", style="Grande.TButton",
                   command=lambda: self._thread(self.instalar_no_novo)).pack(fill="x", pady=4)
        ttk.Label(
            a3, wraplength=800,
            text=("Para instalar: troque o cabo para o celular NOVO (com a tela funcionando e a "
                  "Depuração USB ativada). O programa abre a página de cada app e você só "
                  "toca em Instalar. Os essenciais vêm primeiro."),
        ).pack(anchor="w", pady=8)

        # Aba 4: checklist
        a4 = ttk.Frame(abas, padding=15)
        abas.add(a4, text="4. Checklist")
        self.frame_checks = ttk.Frame(a4)
        self.frame_checks.pack(fill="both", expand=True)
        ttk.Button(a4, text="Gerar relatório final (.txt)", style="Grande.TButton",
                   command=self.gerar_relatorio).pack(fill="x", pady=8)

        # Log
        self.log = scrolledtext.ScrolledText(self, height=10, state="disabled", font=("Consolas", 9))
        self.log.pack(fill="x", padx=10, pady=(0, 10))

    # ------------------------------------------------------------ utilidades
    def escrever(self, texto):
        def _w():
            self.log.configure(state="normal")
            self.log.insert("end", f"[{datetime.now():%H:%M:%S}] {texto}\n")
            self.log.see("end")
            self.log.configure(state="disabled")
        self.after(0, _w)

    def _thread(self, alvo):
        threading.Thread(target=alvo, daemon=True).start()

    def rodar(self, args, timeout=60):
        """Executa o adb e devolve (código, saída)."""
        cmd = [self.adb] + (["-s", self.serial] if self.serial else []) + args
        try:
            r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout,
                               creationflags=NO_WINDOW, encoding="utf-8", errors="replace")
            return r.returncode, (r.stdout + r.stderr).strip()
        except subprocess.TimeoutExpired:
            return 1, "Tempo esgotado."
        except Exception as e:  # noqa: BLE001
            return 1, str(e)

    def _verificar_ferramentas(self):
        faltando = [n for n, p in (("adb", self.adb), ("scrcpy", self.scrcpy)) if not p]
        if faltando:
            self.escrever("Faltando: " + ", ".join(faltando) +
                          ". Coloque na pasta 'ferramentas' ao lado do programa.")
            messagebox.showwarning(
                "Ferramentas ausentes",
                "Não encontrei: " + ", ".join(faltando) + ".\n\n"
                "Baixe o scrcpy (ele já traz o adb) em:\n"
                "github.com/Genymobile/scrcpy/releases\n\n"
                "Extraia o conteúdo na pasta 'ferramentas', ao lado deste programa.")
        else:
            self.escrever("Ferramentas encontradas. Conecte o celular e clique em 'Detectar celular'.")

    def _exigir_celular(self):
        if not self.adb:
            messagebox.showerror("Erro", "ADB não encontrado.")
            return False
        if not self.serial:
            messagebox.showinfo("Atenção", "Clique primeiro em 'Detectar celular'.")
            return False
        return True

    # ---------------------------------------------------------------- aba 1
    def detectar(self):
        if not self.adb:
            self._verificar_ferramentas()
            return
        self.serial = None
        _, saida = self.rodar(["devices"])
        linhas = [l.split() for l in saida.splitlines()[1:] if l.strip()]
        ok = [l[0] for l in linhas if len(l) > 1 and l[1] == "device"]
        nao_aut = [l[0] for l in linhas if len(l) > 1 and l[1] == "unauthorized"]

        if ok:
            self.serial = ok[0]
            _, modelo = self.rodar(["shell", "getprop", "ro.product.model"])
            _, versao = self.rodar(["shell", "getprop", "ro.build.version.release"])
            self.lbl_status.config(text=f"Conectado: {modelo} (Android {versao})")
            self.escrever(f"Celular detectado: {modelo}, Android {versao}, serial {self.serial}.")
            if len(ok) > 1:
                self.escrever("Mais de um aparelho conectado; usando o primeiro. "
                              "Deixe só um por vez.")
        elif nao_aut:
            self.lbl_status.config(text="Celular encontrado, mas não autorizado.")
            self.escrever("Celular pede autorização do computador. Confirme 'Permitir depuração "
                          "USB' na tela (use um mouse OTG se o touch não funcionar).")
        else:
            self.lbl_status.config(text="Nenhum celular conectado.")
            self.escrever("Nenhum celular encontrado. Veja o guia (botão abaixo).")

    def abrir_tela(self):
        if not self._exigir_celular():
            return
        if not self.scrcpy:
            messagebox.showerror("Erro", "scrcpy não encontrado.")
            return
        self.escrever("Abrindo a tela do celular. Use o mouse e o teclado do PC.")
        cmd = [self.scrcpy, "-s", self.serial, "--stay-awake", "--window-title",
               "Celular (use o mouse)"]
        try:
            subprocess.Popen(cmd, creationflags=NO_WINDOW)
        except Exception as e:  # noqa: BLE001
            self.escrever(f"Erro ao abrir scrcpy: {e}")

    def manter_tela(self):
        if not self._exigir_celular():
            return
        self.rodar(["shell", "svc", "power", "stayon", "usb"])
        self.rodar(["shell", "input", "keyevent", "224"])   # acordar tela
        self.rodar(["shell", "input", "swipe", "500", "1500", "500", "500"])  # deslizar
        self.escrever("Tela acordada e mantida ligada enquanto estiver no USB. "
                      "Digite a senha com o teclado do PC na janela do celular.")

    def mostrar_guia(self):
        messagebox.showinfo(
            "Guia: celular não detectado",
            "1. Use outro cabo USB (alguns só carregam, não transferem dados).\n"
            "2. Na barra do celular, escolha 'Transferência de arquivos' (MTP).\n"
            "3. Se a Depuração USB estava DESLIGADA:\n"
            "   - Conecte um mouse USB ao celular com adaptador OTG.\n"
            "   - Configurações > Sobre o telefone > toque 7x em 'Número da versão'.\n"
            "   - Configurações > Opções do desenvolvedor > ative 'Depuração USB'.\n"
            "4. Ao conectar ao PC, aceite 'Permitir depuração USB' (com o mouse OTG).\n"
            "5. No Windows, instale o driver USB do fabricante (Samsung, Motorola, Xiaomi...).\n"
            "6. Clique em 'Detectar celular' de novo.\n\n"
            "Se mesmo assim nada funcionar, a assistência técnica pode trocar só o "
            "display/touch, e os dados ficam intactos.")

    # ---------------------------------------------------------------- aba 2
    def escolher_pasta(self):
        pasta = filedialog.askdirectory(initialdir=self.pasta_backup.get())
        if pasta:
            self.pasta_backup.set(pasta)

    def copiar_arquivos(self):
        if not self._exigir_celular():
            return
        base = os.path.join(self.pasta_backup.get(), f"arquivos_{datetime.now():%Y%m%d_%H%M}")
        os.makedirs(base, exist_ok=True)
        self.escrever(f"Copiando para: {base}")
        copiadas = 0
        for pasta in PASTAS_CELULAR:
            cod, _ = self.rodar(["shell", "ls", pasta])
            if cod != 0:
                continue
            self.escrever(f"Copiando {pasta} ... (pode demorar)")
            cod, saida = self.rodar(["pull", pasta, base], timeout=60 * 60 * 3)
            ultima = saida.splitlines()[-1] if saida else ""
            self.escrever(f"  {ultima}")
            if cod == 0:
                copiadas += 1
        self.escrever(f"Cópia concluída: {copiadas} pasta(s) copiada(s).")
        self._marcar("Arquivos de aula (planos, provas, planilhas) conferidos no PC", False)

    def exportar_contatos_sms(self):
        if not self._exigir_celular():
            return
        destino = os.path.join(self.pasta_backup.get(), "contatos_sms")
        os.makedirs(destino, exist_ok=True)
        # Backup via ADB do provedor de contatos (leitura direta)
        self.escrever("Exportando contatos...")
        _, contatos = self.rodar(
            ["shell", "content", "query", "--uri", "content://com.android.contacts/data/phones",
             "--projection", "display_name:data1"], timeout=120)
        with open(os.path.join(destino, "contatos.txt"), "w", encoding="utf-8") as f:
            f.write(contatos)
        self.escrever("Exportando SMS...")
        _, sms = self.rodar(
            ["shell", "content", "query", "--uri", "content://sms",
             "--projection", "address:date:body"], timeout=300)
        with open(os.path.join(destino, "sms.txt"), "w", encoding="utf-8") as f:
            f.write(sms)
        self.escrever(f"Contatos e SMS salvos em {destino}. "
                      "Dica: sincronizar os contatos com a conta Google é mais seguro.")

    def abrir_backup_google(self):
        if not self._exigir_celular():
            return
        self.rodar(["shell", "am", "start", "-a", "android.settings.PRIVACY_SETTINGS"])
        self.escrever("Tela de backup aberta no celular. Clique com o mouse em "
                      "'Backup do Google' e depois em 'Fazer backup agora'.")

    # ---------------------------------------------------------------- aba 3
    def listar_apps(self):
        if not self._exigir_celular():
            return
        self.escrever("Lendo apps instalados...")
        cod, saida = self.rodar(["shell", "pm", "list", "packages", "-3"])
        if cod != 0:
            self.escrever("Não foi possível listar os apps.")
            return
        pacotes = sorted(l.replace("package:", "").strip() for l in saida.splitlines() if l.strip())
        self.apps_antigos = pacotes
        essenciais = [p for p in pacotes if p in APPS_ESSENCIAIS]

        destino = self.pasta_backup.get()
        os.makedirs(destino, exist_ok=True)
        caminho = os.path.join(destino, "lista_de_apps.json")
        with open(caminho, "w", encoding="utf-8") as f:
            json.dump({"essenciais": essenciais, "todos": pacotes}, f, indent=2, ensure_ascii=False)

        self.escrever(f"{len(pacotes)} apps encontrados, {len(essenciais)} essenciais para professores:")
        for p in essenciais:
            self.escrever(f"  - {APPS_ESSENCIAIS[p]}")
        self.escrever(f"Lista salva em {caminho}")

    def instalar_no_novo(self):
        # Carrega a lista salva, se ainda não foi lida nesta sessão
        if not self.apps_antigos:
            caminho = os.path.join(self.pasta_backup.get(), "lista_de_apps.json")
            if os.path.isfile(caminho):
                with open(caminho, encoding="utf-8") as f:
                    self.apps_antigos = json.load(f)["todos"]
            else:
                self.escrever("Primeiro liste os apps do celular antigo.")
                return
        messagebox.showinfo("Troque o cabo",
                            "Conecte agora o celular NOVO (tela funcionando, Depuração USB ativada) "
                            "e clique em OK.")
        self.detectar()
        if not self.serial:
            return
        ordem = ([p for p in self.apps_antigos if p in APPS_ESSENCIAIS] +
                 [p for p in self.apps_antigos if p not in APPS_ESSENCIAIS])
        self.escrever(f"Abrindo a Play Store para {len(ordem)} apps. Toque em 'Instalar' em cada um.")
        for i, pacote in enumerate(ordem, 1):
            nome = APPS_ESSENCIAIS.get(pacote, pacote)
            self.escrever(f"({i}/{len(ordem)}) {nome}")
            self.rodar(["shell", "am", "start", "-a", "android.intent.action.VIEW",
                        "-d", f"market://details?id={pacote}"])
            time.sleep(6)  # tempo para tocar em Instalar
        self.escrever("Fila concluída. Faça login em cada app e marque o checklist.")

    # ---------------------------------------------------------------- aba 4
    def _carregar_checklist(self):
        caminho = os.path.join(APP_DIR, "checklist.json")
        if os.path.isfile(caminho):
            try:
                with open(caminho, encoding="utf-8") as f:
                    self.estado_check = json.load(f)
            except Exception:  # noqa: BLE001
                self.estado_check = {}
        for item in CHECKLIST_PADRAO:
            var = tk.BooleanVar(value=self.estado_check.get(item, False))
            ttk.Checkbutton(self.frame_checks, text=item, variable=var,
                            command=self._salvar_checklist).pack(anchor="w", pady=3)
            self.checks_vars.append((item, var))

    def _salvar_checklist(self):
        self.estado_check = {item: var.get() for item, var in self.checks_vars}
        with open(os.path.join(APP_DIR, "checklist.json"), "w", encoding="utf-8") as f:
            json.dump(self.estado_check, f, ensure_ascii=False, indent=2)

    def _marcar(self, texto, valor):
        for item, var in self.checks_vars:
            if item == texto:
                self.after(0, lambda v=var: v.set(valor))

    def gerar_relatorio(self):
        self._salvar_checklist()
        destino = self.pasta_backup.get()
        os.makedirs(destino, exist_ok=True)
        caminho = os.path.join(destino, f"relatorio_{datetime.now():%Y%m%d_%H%M}.txt")
        with open(caminho, "w", encoding="utf-8") as f:
            f.write("RELATÓRIO DE MIGRAÇÃO - ResgateMobile\n")
            f.write(f"Data: {datetime.now():%d/%m/%Y %H:%M}\n\n")
            f.write("CHECKLIST\n")
            for item, feito in self.estado_check.items():
                f.write(f"  [{'x' if feito else ' '}] {item}\n")
            f.write(f"\nApps encontrados no celular antigo: {len(self.apps_antigos)}\n")
            essenciais = [APPS_ESSENCIAIS[p] for p in self.apps_antigos if p in APPS_ESSENCIAIS]
            f.write("Apps essenciais: " + (", ".join(essenciais) or "nenhum") + "\n")
        self.escrever(f"Relatório salvo em {caminho}")
        messagebox.showinfo("Relatório", f"Relatório salvo em:\n{caminho}")


if __name__ == "__main__":
    try:
        ResgateMobile().mainloop()
    except tk.TclError as erro:
        print("Não foi possível abrir a interface gráfica:", erro)
        sys.exit(1)
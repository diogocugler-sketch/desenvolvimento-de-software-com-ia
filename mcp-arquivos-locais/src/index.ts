import { promises as fs } from "node:fs";
import path from "node:path";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

const MAX_FILE_SIZE = 1024 * 1024;
const rootSetting = process.env.MCP_FILES_ROOT;

if (!rootSetting) {
  throw new Error("Defina MCP_FILES_ROOT com o caminho da pasta autorizada.");
}

const allowedRoot = await fs.realpath(path.resolve(rootSetting));

function isWithinRoot(target: string): boolean {
  const relative = path.relative(allowedRoot, target);
  return relative === "" || (!relative.startsWith(`..${path.sep}`) && relative !== ".." && !path.isAbsolute(relative));
}

async function resolveExistingPath(relativePath: string): Promise<string> {
  const candidate = path.resolve(allowedRoot, relativePath);
  if (!isWithinRoot(candidate)) {
    throw new Error("O caminho solicitado está fora da pasta autorizada.");
  }

  const resolved = await fs.realpath(candidate);
  if (!isWithinRoot(resolved)) {
    throw new Error("O caminho solicitado aponta para fora da pasta autorizada.");
  }

  return resolved;
}

function toolError(error: unknown) {
  const message = error instanceof Error ? error.message : "Erro inesperado ao acessar o arquivo.";
  return { content: [{ type: "text" as const, text: message }], isError: true };
}

const server = new McpServer({
  name: "mcp-arquivos-locais",
  version: "1.0.0"
});

server.tool(
  "list_directory",
  "Lista arquivos e subpastas de um diretório dentro da raiz autorizada.",
  { path: z.string().default(".") },
  async ({ path: relativePath }) => {
    try {
      const directory = await resolveExistingPath(relativePath);
      const info = await fs.stat(directory);
      if (!info.isDirectory()) {
        throw new Error("O caminho informado não é um diretório.");
      }

      const entries = await fs.readdir(directory, { withFileTypes: true });
      const result = entries.map((entry) => ({
        name: entry.name,
        type: entry.isDirectory() ? "directory" : entry.isFile() ? "file" : "other"
      }));

      return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
    } catch (error) {
      return toolError(error);
    }
  }
);

server.tool(
  "read_file",
  "Lê um arquivo de texto de até 1 MB dentro da raiz autorizada.",
  { path: z.string().min(1) },
  async ({ path: relativePath }) => {
    try {
      const filePath = await resolveExistingPath(relativePath);
      const info = await fs.stat(filePath);
      if (!info.isFile()) {
        throw new Error("O caminho informado não é um arquivo.");
      }
      if (info.size > MAX_FILE_SIZE) {
        throw new Error("O arquivo excede o limite de leitura de 1 MB.");
      }

      const content = await fs.readFile(filePath, "utf8");
      return { content: [{ type: "text", text: content }] };
    } catch (error) {
      return toolError(error);
    }
  }
);

const transport = new StdioServerTransport();
await server.connect(transport);

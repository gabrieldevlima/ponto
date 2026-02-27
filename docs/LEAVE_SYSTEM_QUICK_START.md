# Guia Rápido: Sistema de Afastamentos

## 🚀 Instalação Rápida

```bash
# 1. Execute o script SQL
mysql -u usuario -p ponto < install_leaves_attachments.sql

# 2. Defina permissões
chmod 755 public/attachments/leaves/
```

## ✅ Checklist Pós-Instalação

- [ ] Script SQL executado com sucesso
- [ ] Permissões do diretório `public/attachments/leaves/` configuradas
- [ ] Menu "Afastamentos" visível no painel admin
- [ ] Tipos de afastamento padrão inseridos

## 📝 Como Usar

### Cadastrar Afastamento

1. **Menu Admin** → **Afastamentos**
2. Preencha:
   - Colaborador (obrigatório)
   - Tipo de afastamento (obrigatório)
   - Data início e fim (obrigatório)
   - Descrição, CID-10, Atestado (opcional)
3. **Salvar**

### Verificar nos Relatórios

- **Relatório Mensal**: Mostra afastamentos por dia + seção dedicada
- **Relatório Financeiro**: Mostra impacto financeiro dos afastamentos

## ⚙️ Configuração de Tipos

Acesse **Tipos de Licença/Afastamento**:

- ✅ **Remunerado**: Zera horas esperadas (sem desconto)
- ❌ **Não Remunerado**: Mantém horas (pode gerar desconto)
- 📎 **Requer Anexo**: Obriga upload de atestado

## 🎯 Regras Principais

| Tipo | Remunerado | Impacto no Salário | Exemplo |
|------|------------|-------------------|---------|
| Atestado Médico | ✅ Sim | Nenhum | Professor doente |
| Férias | ✅ Sim | Nenhum | Férias regulares |
| Suspensão | ❌ Não | Desconto | Suspensão disciplinar |

## 📊 O Que o Sistema Faz Automaticamente

✅ Calcula dias de afastamento  
✅ Zera horas esperadas (se remunerado)  
✅ Exibe nos relatórios mensais  
✅ Calcula impacto financeiro  
✅ Registra acessos aos atestados (LGPD)  
✅ Gera seções específicas nos PDFs

## 🔒 Segurança

- Atestados protegidos por autenticação
- Log de auditoria para cada acesso
- Armazenamento seguro com nomes únicos
- Respeita escopo de admin (network/school)

## 🆘 Problemas Comuns

**Afastamento não aparece no relatório**
→ Verifique se está **Aprovado** e dentro do período

**Upload falha**
→ Verifique permissões: `chmod 755 public/attachments/leaves/`

**Desconto indevido**
→ Confirme se o tipo está marcado como **Remunerado**

---

📚 **Documentação Completa**: `docs/LEAVE_MANAGEMENT_SYSTEM.md`


import { Container } from "@/components/ui/container";
import { Page } from "@/components/ui/page";
import { Prose } from "@/components/ui/prose";

export function RichTextPage({ body, title }: { body: string; title: string }) {
  return (
    <Page>
      <Container className="max-w-2xl">
        <Prose>
          <h1>{title}</h1>
          <div
            // oxlint-disable-next-line react/no-danger -- WordPress filters published post content.
            dangerouslySetInnerHTML={{ __html: body }}
          />
        </Prose>
      </Container>
    </Page>
  );
}
